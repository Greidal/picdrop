<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/metadata.php';
require_once __DIR__ . '/lib/drinks.php';

requireLogin();

$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);
$canManageTeam = canManageEventTeam($conn, $uuid);

$flash = getFlashMessage();
$msg = $flash ? $flash['text'] : "";
$msgClass = $flash ? $flash['type'] : "";

function redirectSelf($uuid, string $anchor = '')
{
    header("Location: manage_event.php?event=" . urlencode($uuid) . ($anchor !== '' ? "#$anchor" : ''));
    exit;
}

/** Reads the drink fields of the add/edit row, clamped to sensible values. */
function drinkFormValues(array $post): array
{
    return [
        'name' => mb_substr(trim((string) ($post['drink_name'] ?? '')), 0, 100),
        'volume' => max(1, min(3000, (int) ($post['drink_volume'] ?? 500))),
        'abv' => max(0.0, min(96.0, (float) str_replace(',', '.', (string) ($post['drink_abv'] ?? 5)))),
        'score' => max(0.0, min(999.9, (float) str_replace(',', '.', (string) ($post['drink_score'] ?? 1)))),
    ];
}

function deleteEventFile(?string $path, string $uuid): void
{
    if ($path && str_starts_with($path, "uploads/$uuid/") && is_file($path)) {
        unlink($path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (isset($_POST['rename_event'])) {
        $newName = trim($_POST['event_name'] ?? '');
        $nameLength = mb_strlen($newName);

        if ($newName === '') {
            $msg = "Bitte einen neuen Event-Namen eingeben.";
            $msgClass = "error";
        } elseif ($nameLength > 100) {
            $msg = "Der Event-Name darf maximal 100 Zeichen lang sein.";
            $msgClass = "error";
        } else {
            $upd = $conn->prepare("UPDATE events SET name = ? WHERE uuid = ?");
            $upd->bind_param("ss", $newName, $uuid);

            if ($upd->execute()) {
                setFlashMessage("Event erfolgreich umbenannt!", "success");
                redirectSelf($uuid);
            } else {
                $msg = "Fehler beim Umbenennen des Events.";
                $msgClass = "error";
            }
        }
    }

    if (isset($_POST['upload_logo'])) {
        $targetFile = storeUploadedImage($_FILES['event_logo'] ?? [], "uploads/$uuid");
        if ($targetFile !== null) {
            $old = $conn->prepare("SELECT logo_path FROM events WHERE uuid = ?");
            $old->bind_param("s", $uuid);
            $old->execute();
            deleteEventFile($old->get_result()->fetch_assoc()['logo_path'] ?? null, $uuid);

            $logoStmt = $conn->prepare("UPDATE events SET logo_path = ? WHERE uuid = ?");
            $logoStmt->bind_param("ss", $targetFile, $uuid);
            $logoStmt->execute();
            setFlashMessage("Logo hochgeladen!", "success");
            redirectSelf($uuid);
        }
        $msg = "Fehler beim Upload des Logos (erlaubt: JPG, PNG, WebP, GIF).";
        $msgClass = "error";
    }

    if (isset($_POST['update_settings'])) {
        $s_badge = isset($_POST['s_badge']) ? 1 : 0;
        $s_uploader = isset($_POST['s_uploader']) ? 1 : 0;
        $s_time = isset($_POST['s_time']) ? 1 : 0;
        $s_bar = isset($_POST['s_bar']) ? 1 : 0;
        $s_evtname = isset($_POST['s_evtname']) ? 1 : 0;
        $s_merge = isset($_POST['s_merge']) ? 1 : 0;
        $s_strip = isset($_POST['s_strip_location']) ? 1 : 0;
        $s_capture = isset($_POST['s_capture_time']) ? 1 : 0;
        $s_show_location = isset($_POST['s_show_location']) ? 1 : 0;
        $s_bac = isset($_POST['s_bac']) ? 1 : 0;
        $location = mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 100);
        $location = $location === '' ? null : $location;
        $s_duration = (int) round(max(2, min(600, (float) str_replace(',', '.', (string) ($_POST['s_duration'] ?? 8)))) * 1000);

        $prev = $conn->prepare("SELECT setting_strip_location FROM events WHERE uuid = ?");
        $prev->bind_param("s", $uuid);
        $prev->execute();
        $wasStripping = !empty($prev->get_result()->fetch_assoc()['setting_strip_location']);

        $upd = $conn->prepare("UPDATE events SET setting_show_badge=?, setting_show_uploader=?, setting_show_time=?, setting_show_event_name=?,
            setting_show_bar=?, setting_merge_by_device=?, setting_strip_location=?, setting_use_capture_time=?, setting_show_location=?,
            setting_show_bac=?, location=?, setting_slide_duration=? WHERE uuid=?");
        $upd->bind_param("iiiiiiiiiisis", $s_badge, $s_uploader, $s_time, $s_evtname, $s_bar, $s_merge, $s_strip, $s_capture,
            $s_show_location, $s_bac, $location, $s_duration, $uuid);
        $upd->execute();

        $savedMsg = "Einstellungen gespeichert!";
        if ($s_strip && !$wasStripping) {
            // Newly enabled: clean the photos that were uploaded before.
            $counts = stripLocationFromEvent($conn, $uuid);
            $savedMsg .= " Standortdaten aus {$counts[METADATA_STRIPPED]} vorhandenen Fotos entfernt.";
            if ($counts[METADATA_UNSUPPORTED] + $counts[METADATA_FAILED] > 0) {
                $savedMsg .= " " . ($counts[METADATA_UNSUPPORTED] + $counts[METADATA_FAILED]) . " Fotos konnten nicht bearbeitet werden (z. B. HEIC).";
            }
        }
        setFlashMessage($savedMsg, "success");
        redirectSelf($uuid);
    }

    if (isset($_POST['delete_logo'])) {
        $stmt = $conn->prepare("SELECT logo_path FROM events WHERE uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        deleteEventFile($stmt->get_result()->fetch_assoc()['logo_path'] ?? null, $uuid);
        $upd = $conn->prepare("UPDATE events SET logo_path = NULL WHERE uuid = ?");
        $upd->bind_param("s", $uuid);
        $upd->execute();

        setFlashMessage("Logo entfernt.", "success");
        redirectSelf($uuid);
    }

    if (isset($_POST['invite_email'])) {
        $email = mb_strtolower(trim((string) $_POST['invite_email']));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage("Ungültige E-Mail-Adresse.", "error");
            redirectSelf($uuid, 'team');
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $existingUser = $res->fetch_assoc();
            $uid = $existingUser['id'];

            $check = $conn->prepare("SELECT 1 FROM event_users WHERE event_uuid = ? AND user_id = ?");
            $check->bind_param("si", $uuid, $uid);
            $check->execute();
            if ($check->get_result()->num_rows == 0) {
                $ins = $conn->prepare("INSERT INTO event_users (event_uuid, user_id) VALUES (?, ?)");
                $ins->bind_param("si", $uuid, $uid);
                $ins->execute();
                sendEventAccessMail($email, getEventOrDie($conn, $uuid), $uuid);

                setFlashMessage("User existiert bereits. Zugriff gewährt & E-Mail gesendet!", "success");
                redirectSelf($uuid, 'team');
            } else {
                $msg = "User hat bereits Zugriff.";
                $msgClass = "error";
            }
        } else {
            $token = bin2hex(random_bytes(16));

            $stmt = $conn->prepare("INSERT INTO event_invites (event_uuid, email, token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("sss", $uuid, $email, $token);

            if ($stmt->execute()) {
                sendInviteMail($email, getEventOrDie($conn, $uuid), $token);
                setFlashMessage("Einladungs-Link per E-Mail gesendet!", "success");
                redirectSelf($uuid, 'team');
            } else {
                $msg = "Datenbankfehler beim Einladen.";
                $msgClass = "error";
            }
        }
    }

    if (isset($_POST['remove_user_id']) || isset($_POST['cancel_invite_id'])) {
        if (!$canManageTeam) {
            http_response_code(403);
            die("⛔ Nur wer das Event erstellt hat, kann Personen entfernen.");
        }
        if (isset($_POST['remove_user_id'])) {
            $removeId = (int) $_POST['remove_user_id'];
            // The creator can't be removed (would leave the event without an owner).
            $del = $conn->prepare("DELETE eu FROM event_users eu JOIN events e ON e.uuid = eu.event_uuid
                WHERE eu.event_uuid = ? AND eu.user_id = ? AND NOT (e.created_by <=> eu.user_id)");
            $del->bind_param("si", $uuid, $removeId);
            $del->execute();
            setFlashMessage($del->affected_rows > 0 ? "Zugriff entfernt." : "Diese Person kann nicht entfernt werden.", $del->affected_rows > 0 ? "success" : "error");
        } else {
            $inviteId = (int) $_POST['cancel_invite_id'];
            $del = $conn->prepare("DELETE FROM event_invites WHERE event_uuid = ? AND id = ?");
            $del->bind_param("si", $uuid, $inviteId);
            $del->execute();
            setFlashMessage("Einladung zurückgezogen.", "success");
        }
        redirectSelf($uuid, 'team');
    }

    if (isset($_POST['delete_drink_id'])) {
        $did = intval($_POST['delete_drink_id']);

        $stmt = $conn->prepare("SELECT image_path FROM drinks WHERE id=? AND event_uuid=?");
        $stmt->bind_param("is", $did, $uuid);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            deleteEventFile($row['image_path'], $uuid);

            $del = $conn->prepare("DELETE FROM drinks WHERE id=? AND event_uuid=?");
            $del->bind_param("is", $did, $uuid);
            $del->execute();

            setFlashMessage("Getränk gelöscht.", "success");
        }
        redirectSelf($uuid, 'drinks');
    }

    if (isset($_POST['add_drink'])) {
        $v = drinkFormValues($_POST);
        $hasImage = ($_FILES['drink_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $targetFile = $hasImage ? storeUploadedImage($_FILES['drink_image'], "uploads/$uuid/drinks") : null;

        if ($v['name'] === '') {
            $msg = "Bitte einen Namen für das Getränk angeben.";
            $msgClass = "error";
        } elseif ($hasImage && $targetFile === null) {
            $msg = "Fehler beim Upload des Bildes (erlaubt: JPG, PNG, WebP, GIF).";
            $msgClass = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO drinks (event_uuid, name, image_path, score_factor, volume_ml, abv) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssdid", $uuid, $v['name'], $targetFile, $v['score'], $v['volume'], $v['abv']);
            $stmt->execute();

            setFlashMessage("Getränk hinzugefügt!", "success");
            redirectSelf($uuid, 'drinks');
        }
    }

    if (isset($_POST['update_drink_id'])) {
        $did = (int) $_POST['update_drink_id'];
        $v = drinkFormValues($_POST);
        $stmt = $conn->prepare("SELECT image_path FROM drinks WHERE id = ? AND event_uuid = ?");
        $stmt->bind_param("is", $did, $uuid);
        $stmt->execute();
        $drink = $stmt->get_result()->fetch_assoc();

        if ($drink && $v['name'] !== '') {
            $imagePath = $drink['image_path'];
            if (($_FILES['drink_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newImage = storeUploadedImage($_FILES['drink_image'], "uploads/$uuid/drinks");
                if ($newImage === null) {
                    setFlashMessage("Fehler beim Upload des Bildes (erlaubt: JPG, PNG, WebP, GIF).", "error");
                    redirectSelf($uuid, 'drinks');
                }
                deleteEventFile($imagePath, $uuid);
                $imagePath = $newImage;
            }
            $upd = $conn->prepare("UPDATE drinks SET name = ?, image_path = ?, score_factor = ?, volume_ml = ?, abv = ? WHERE id = ? AND event_uuid = ?");
            $upd->bind_param("ssdidis", $v['name'], $imagePath, $v['score'], $v['volume'], $v['abv'], $did, $uuid);
            $upd->execute();
            setFlashMessage("Getränk „{$v['name']}“ gespeichert.", "success");
        }
        redirectSelf($uuid, 'drinks');
    }

    if (isset($_POST['add_team'])) {
        if (drinkCreateTeam($conn, $uuid, (string) $_POST['team_name']) !== null) {
            setFlashMessage("Team angelegt.", "success");
        }
        redirectSelf($uuid, 'drinks');
    }

    if (isset($_POST['delete_team_id'])) {
        $tid = (int) $_POST['delete_team_id'];
        $del = $conn->prepare("DELETE FROM teams WHERE id = ? AND event_uuid = ?");
        $del->bind_param("is", $tid, $uuid);
        $del->execute();
        setFlashMessage("Team gelöscht.", "success");
        redirectSelf($uuid, 'drinks');
    }

    if (isset($_POST['delete_event_final']) && $_POST['delete_event_final'] === 'yes') {
        removeDirectory(__DIR__ . "/uploads/$uuid");

        $stmt = $conn->prepare("DELETE FROM events WHERE uuid = ?");
        $stmt->bind_param("s", $uuid);

        if ($stmt->execute()) {
            setFlashMessage("Event erfolgreich gelöscht.", "success");
            header("Location: admin.php");
            exit;
        } else {
            $msg = "Fehler beim Löschen des Events.";
            $msgClass = "error";
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM events WHERE uuid = ?");
$stmt->bind_param("s", $uuid);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

$drinkStmt = $conn->prepare("SELECT d.*, (SELECT COUNT(*) FROM checkins c WHERE c.drink_id = d.id) AS checkins FROM drinks d WHERE d.event_uuid = ? ORDER BY d.id");
$drinkStmt->bind_param("s", $uuid);
$drinkStmt->execute();
$drinks = $drinkStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$team = $conn->prepare("SELECT u.id, u.username, u.email FROM users u JOIN event_users eu ON u.id = eu.user_id WHERE eu.event_uuid = ? ORDER BY eu.id");
$team->bind_param("s", $uuid);
$team->execute();
$members = $team->get_result()->fetch_all(MYSQLI_ASSOC);

$invStmt = $conn->prepare("SELECT id, email FROM event_invites WHERE event_uuid = ? ORDER BY created_at");
$invStmt->bind_param("s", $uuid);
$invStmt->execute();
$invites = $invStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$teams = drinkTeams($conn, $uuid);

$appUrl = appBaseUrl() . "/?event=" . urlencode($uuid);
$qrCode = (new chillerlan\QRCode\QRCode())->render($appUrl);
$q = 'event=' . urlencode($uuid);
$pageTitle = "Verwalten: " . $event['name'];
require __DIR__ . '/lib/header.php';

/** One toggle with title and optional explanation. */
function settingToggle(string $name, bool $checked, string $title, string $hint = ''): string
{
    $html = '<label class="toggle-switch setting"><input type="checkbox" name="' . e($name) . '"' . ($checked ? ' checked' : '') . '>'
        . '<span class="slider"></span><span><span class="setting-title">' . e($title) . '</span>';
    if ($hint !== '') {
        $html .= '<small class="setting-hint">' . e($hint) . '</small>';
    }
    return $html . '</span></label>';
}
?>

<style>
    .container {
        max-width: 960px;
    }

    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .title-row {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 18px 0 20px;
        min-width: 0;
    }

    .title-row h1 {
        margin: 0;
        overflow-wrap: anywhere;
    }

    .title-row form {
        display: flex;
        gap: 6px;
        flex: 1;
        margin: 0;
    }

    .title-row form input {
        margin: 0;
        font-size: 1.3rem;
    }

    .line-card {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        padding: 14px 18px;
    }

    .line-label {
        font-weight: bold;
        min-width: 90px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .line-label i {
        color: var(--primary);
        width: 18px;
    }

    .logo-preview {
        width: 120px;
        height: 56px;
        border-radius: 8px;
        border: 1px dashed #444;
        background: #000;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        color: #555;
        font-size: 0.75rem;
        flex-shrink: 0;
    }

    .logo-preview img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .link-field {
        flex: 1;
        min-width: 200px;
        margin: 0 !important;
        font-size: 0.9rem !important;
        color: #ccc !important;
    }

    .qr-thumb {
        width: 44px;
        height: 44px;
        border-radius: 6px;
        background: white;
        cursor: zoom-in;
        flex-shrink: 0;
    }

    .settings-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .settings-grid .card {
        margin-bottom: 0;
    }

    .card h3 {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 16px;
    }

    .card h3 i {
        color: var(--primary);
        font-size: 1rem;
    }

    .toggle-switch.setting {
        align-items: flex-start;
        margin-bottom: 14px;
    }

    .toggle-switch.setting .slider {
        flex-shrink: 0;
        margin-top: 1px;
    }

    .setting-hint {
        display: block;
        font-size: 0.78rem;
        margin-top: 2px;
        line-height: 1.35;
    }

    .sub-field {
        margin: -6px 0 14px 50px;
    }

    .sub-field input {
        margin: 0;
        padding: 8px 10px;
        font-size: 0.9rem;
    }

    .duration-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 6px;
    }

    .duration-row output {
        min-width: 44px;
        text-align: right;
        font-weight: bold;
    }

    .save-row {
        display: flex;
        justify-content: flex-end;
        margin: 16px 0 20px;
    }

    /* Drink menu as a table that turns into cards on phones */
    .drink-table {
        display: grid;
        gap: 6px;
    }

    .drink-row {
        display: grid;
        grid-template-columns: 52px minmax(120px, 1fr) 96px 86px 86px 84px;
        gap: 8px;
        align-items: center;
        margin: 0;
        padding: 6px 8px;
        border-bottom: 1px solid #262626;
    }

    .drink-row.head {
        color: var(--text-muted);
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--border);
    }

    .drink-row input[type="text"],
    .drink-row input[type="number"] {
        margin: 0;
        padding: 8px;
        font-size: 0.95rem;
    }

    .drink-row .unit {
        position: relative;
    }

    .drink-row .unit::after {
        content: attr(data-unit);
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        color: #666;
        font-size: 0.8rem;
        pointer-events: none;
    }

    .drink-row .unit input {
        padding-right: 30px;
    }

    .drink-img {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        background: #000;
        border: 1px solid #333;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        cursor: pointer;
        position: relative;
        font-size: 1.4rem;
    }

    .drink-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .drink-img .cam {
        position: absolute;
        right: 2px;
        bottom: 2px;
        font-size: 0.6rem;
        color: #fff;
        background: rgba(0, 0, 0, 0.6);
        border-radius: 4px;
        padding: 2px 3px;
    }

    .drink-img.changed {
        border-color: var(--success);
    }

    .drink-actions {
        display: flex;
        justify-content: flex-end;
    }

    .drink-row.add {
        border-bottom: none;
        background: #151515;
        border-radius: 10px;
        padding: 8px;
        margin-top: 6px;
    }

    .drink-row .uses {
        font-size: 0.7rem;
        color: #666;
        display: block;
        margin-top: 2px;
    }

    .template-row {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .template-row select {
        margin: 0;
        width: auto;
        padding: 8px;
        font-size: 0.9rem;
    }

    .teams-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 18px;
        padding-top: 14px;
        border-top: 1px solid var(--border);
    }

    .teams-row form {
        margin: 0;
    }

    .chip .icon-btn {
        width: 24px;
        height: 24px;
        font-size: 0.75rem;
    }

    .people {
        list-style: none;
        padding: 0;
        margin: 14px 0 0;
    }

    .people li {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 6px 0;
        border-bottom: 1px solid #222;
        font-size: 0.92rem;
    }

    .people li form {
        margin: 0 0 0 auto;
    }

    .initial {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #2a2a2a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 0.8rem;
        flex-shrink: 0;
        text-transform: uppercase;
    }

    .people .who {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .role {
        font-size: 0.7rem;
        border: 1px solid #444;
        border-radius: 10px;
        padding: 1px 7px;
        color: #aaa;
        margin-left: 6px;
    }

    .pending .initial {
        background: transparent;
        border: 1px dashed #555;
        color: #777;
    }

    #toast {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #222;
        border: 1px solid var(--success);
        color: var(--success);
        padding: 10px 18px;
        border-radius: 30px;
        z-index: 2000;
        font-weight: bold;
    }

    #qrModal img {
        width: 100%;
        max-width: 320px;
        background: white;
        border-radius: 10px;
    }

    @media (max-width: 760px) {
        .settings-grid {
            grid-template-columns: 1fr;
        }

        .drink-row.head {
            display: none;
        }

        .drink-row {
            grid-template-columns: 52px 1fr 1fr 1fr;
            grid-template-areas:
                "img name name actions"
                "img vol abv score";
            background: #151515;
            border-radius: 10px;
            padding: 8px;
            border-bottom: none;
        }

        .drink-row > .drink-img { grid-area: img; align-self: start; }
        .drink-row > .c-name { grid-area: name; }
        .drink-row > .c-vol { grid-area: vol; }
        .drink-row > .c-abv { grid-area: abv; }
        .drink-row > .c-score { grid-area: score; }
        .drink-row > .drink-actions { grid-area: actions; }

        .sub-field {
            margin-left: 0;
        }

        .line-card {
            padding: 12px 14px;
        }

        .line-label {
            min-width: 0;
            flex-basis: 100%;
        }
    }
</style>

<div class="container">
    <div class="top-bar">
        <a href="admin.php" class="btn btn-secondary btn-small"><i class="fa fa-arrow-left"></i> Dashboard</a>
        <a href="manage_guests.php?<?php echo e($q); ?>" class="btn btn-secondary btn-small">👮 Gäste & Spam</a>
    </div>

    <div class="title-row">
        <h1 id="title-view">⚙️ <?php echo e($event['name']); ?></h1>
        <button type="button" id="title-edit" class="icon-btn" title="Namen bearbeiten" aria-label="Namen bearbeiten" onclick="toggleRename(true)"><i class="fa fa-pen"></i></button>
        <form method="post" id="rename-form" class="hidden">
            <?php echo csrfField(); ?>
            <input type="text" id="eventName" name="event_name" value="<?php echo e($event['name']); ?>" maxlength="100" required aria-label="Event-Name">
            <button type="submit" name="rename_event" value="1" class="icon-btn" title="Umbenennen" aria-label="Umbenennen"><i class="fa fa-check"></i></button>
            <button type="button" class="icon-btn" title="Abbrechen" aria-label="Abbrechen" onclick="toggleRename(false)"><i class="fa fa-xmark"></i></button>
        </form>
    </div>

    <?php echo renderMessage($msg, $msgClass); ?>

    <!-- 2. Logo -->
    <div class="card line-card">
        <span class="line-label"><i class="fa fa-image"></i> Logo</span>
        <div class="logo-preview">
            <?php if (!empty($event['logo_path'])): ?>
                <img src="<?php echo e($event['logo_path']); ?>" alt="Event-Logo">
            <?php else: ?>
                kein Logo
            <?php endif; ?>
        </div>
        <small style="flex:1; min-width:150px;">Wird oben links in der Slideshow angezeigt. PNG mit transparentem Hintergrund wirkt am besten.</small>
        <form method="post" enctype="multipart/form-data" style="margin:0;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="upload_logo" value="1">
            <label class="btn btn-secondary btn-small" for="eventLogo"><i class="fa fa-upload"></i> <?php echo empty($event['logo_path']) ? 'Hochladen' : 'Ersetzen'; ?></label>
            <input id="eventLogo" type="file" name="event_logo" accept="image/*" class="hidden" onchange="this.form.submit()">
        </form>
        <?php if (!empty($event['logo_path'])): ?>
            <form method="post" style="margin:0;">
                <?php echo csrfField(); ?>
                <button class="icon-btn danger" name="delete_logo" value="1" title="Logo entfernen" aria-label="Logo entfernen"><i class="fa fa-trash"></i></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- 3. Links -->
    <div class="card line-card">
        <span class="line-label"><i class="fa fa-link"></i> Gäste-Link</span>
        <input type="text" value="<?php echo e($appUrl); ?>" id="shareLink" class="link-field" readonly onclick="this.select()">
        <button type="button" class="icon-link" onclick="copyLink()" title="Link kopieren"><i class="fa fa-copy"></i><span>Kopieren</span></button>
        <a class="icon-link" href="<?php echo e($appUrl); ?>" target="_blank" title="Link öffnen"><i class="fa fa-arrow-up-right-from-square"></i><span>Öffnen</span></a>
        <img src="<?php echo e($qrCode); ?>" alt="QR-Code zum Event" class="qr-thumb" onclick="document.getElementById('qrModal').style.display='flex'">
        <a class="icon-link" href="qr.php?<?php echo e($q); ?>&format=png" title="QR-Code als PNG herunterladen"><i class="fa fa-qrcode"></i><span>PNG</span></a>
        <a class="icon-link" href="qr.php?<?php echo e($q); ?>&format=svg" title="QR-Code als SVG herunterladen"><i class="fa fa-qrcode"></i><span>SVG</span></a>
    </div>

    <!-- 4. + 5. Upload and slideshow settings -->
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="update_settings" value="1">
        <div class="settings-grid">
            <div class="card">
                <h3><i class="fa fa-cloud-arrow-up"></i> Bild-Upload</h3>
                <?php
                echo settingToggle('s_merge', (bool) $event['setting_merge_by_device'], 'Leaderboard intelligent zusammenfassen',
                    'An: Alle Uploads eines Geräts zählen zusammen (Schutz gegen Fake-Namen). Aus: Jeder Name zählt separat.');
                echo settingToggle('s_capture_time', (bool) $event['setting_use_capture_time'], 'Aufnahmezeit der Fotos verwenden',
                    'An: Uhrzeit aus den Foto-Metadaten (z. B. bei später hochgeladenen Fotos). Aus: Zeitpunkt des Uploads.');
                echo settingToggle('s_strip_location', (bool) $event['setting_strip_location'], 'Standortdaten (GPS) aus Fotos entfernen',
                    'Entfernt GPS-Koordinaten ohne Qualitätsverlust – auch aus bereits hochgeladenen Fotos. HEIC wird nicht unterstützt.');
                echo settingToggle('s_bar', (bool) $event['setting_show_bar'], 'Getränkemodus aktivieren',
                    'Gäste checken Getränke ein, sehen „Mein Pegel“, Abzeichen und treten in Teams gegeneinander an.');
                ?>
            </div>

            <div class="card">
                <h3><i class="fa fa-tv"></i> Slideshow</h3>
                <?php
                echo settingToggle('s_uploader', (bool) $event['setting_show_uploader'], 'Name & Getränk anzeigen');
                echo settingToggle('s_time', (bool) $event['setting_show_time'], 'Uhrzeit anzeigen');
                echo settingToggle('s_evtname', (bool) $event['setting_show_event_name'], 'Event-Name anzeigen');
                echo settingToggle('s_show_location', (bool) $event['setting_show_location'], 'Ort anzeigen');
                ?>
                <div class="sub-field">
                    <input type="text" name="location" value="<?php echo e($event['location'] ?? ''); ?>" maxlength="100" placeholder="z. B. Partykeller Regensburg" aria-label="Ort">
                </div>
                <?php
                echo settingToggle('s_badge', (bool) $event['setting_show_badge'], '„NEU“-Badge anzeigen');
                echo settingToggle('s_bac', (bool) $event['setting_show_bac'], 'Pegel des Fotografen einblenden',
                    'Zeigt den geschätzten Promillewert zum Zeitpunkt des Fotos (nur im Getränkemodus).');
                $seconds = $event['setting_slide_duration'] / 1000;
                ?>
                <label for="s_duration">Anzeigedauer pro Bild</label>
                <div class="duration-row">
                    <input type="range" id="s_duration" name="s_duration" min="2" max="60" step="1" value="<?php echo e(min(60, max(2, (int) round($seconds)))); ?>"
                        oninput="this.nextElementSibling.value = this.value + ' s'">
                    <output><?php echo e((int) round($seconds)); ?> s</output>
                </div>
            </div>
        </div>
        <div class="save-row">
            <button type="submit" class="btn btn-primary btn-inline"><i class="fa fa-floppy-disk"></i> Speichern</button>
        </div>
    </form>

    <!-- 6. Drink menu -->
    <div class="card" id="drinks">
        <h3><i class="fa fa-martini-glass-citrus"></i> Getränkekarte</h3>
        <div class="drink-table">
            <div class="drink-row head">
                <span>Bild</span><span>Name</span><span>Menge</span><span>Alkohol</span><span>Punkte</span><span></span>
            </div>
            <?php foreach ($drinks as $d): ?>
                <form method="post" enctype="multipart/form-data" class="drink-row">
                    <?php echo csrfField(); ?>
                    <label class="drink-img" title="Bild ändern">
                        <?php if ($d['image_path']): ?>
                            <img src="<?php echo e($d['image_path']); ?>" alt="">
                        <?php else: ?>
                            🍹
                        <?php endif; ?>
                        <span class="cam"><i class="fa fa-camera"></i></span>
                        <input type="file" name="drink_image" accept="image/*" class="hidden" onchange="this.parentElement.classList.add('changed')">
                    </label>
                    <div class="c-name">
                        <input type="text" name="drink_name" value="<?php echo e($d['name']); ?>" maxlength="100" required aria-label="Name">
                        <span class="uses"><?php echo (int) $d['checkins']; ?>× eingecheckt</span>
                    </div>
                    <div class="unit c-vol" data-unit="ml"><input type="number" name="drink_volume" value="<?php echo (int) $d['volume_ml']; ?>" min="1" max="3000" aria-label="Standardmenge in ml"></div>
                    <div class="unit c-abv" data-unit="%"><input type="number" name="drink_abv" value="<?php echo e((float) $d['abv']); ?>" min="0" max="96" step="0.1" aria-label="Alkoholgehalt in Vol-%"></div>
                    <div class="unit c-score" data-unit="×"><input type="number" name="drink_score" value="<?php echo e((float) $d['score_factor']); ?>" min="0" step="0.1" aria-label="Punkte-Faktor"></div>
                    <div class="drink-actions">
                        <button class="icon-btn" name="update_drink_id" value="<?php echo (int) $d['id']; ?>" title="Änderungen übernehmen" aria-label="Änderungen übernehmen"><i class="fa fa-check"></i></button>
                        <button class="icon-btn danger" name="delete_drink_id" value="<?php echo (int) $d['id']; ?>" formnovalidate title="Getränk löschen" aria-label="Getränk löschen"
                            onclick="return confirm('Getränk wirklich löschen? Alle Check-ins dieses Getränks werden mit gelöscht.')"><i class="fa fa-trash"></i></button>
                    </div>
                </form>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data" class="drink-row add" id="add-drink">
                <?php echo csrfField(); ?>
                <input type="hidden" name="add_drink" value="1">
                <label class="drink-img" title="Bild hinzufügen (optional)">
                    <i class="fa fa-plus" style="font-size:1rem; color:#777;"></i>
                    <input type="file" name="drink_image" accept="image/*" class="hidden" onchange="this.parentElement.classList.add('changed')">
                </label>
                <input type="text" class="c-name" name="drink_name" placeholder="Name (z.B. Bier)" maxlength="100" required aria-label="Name des neuen Getränks">
                <div class="unit c-vol" data-unit="ml"><input type="number" name="drink_volume" value="500" min="1" max="3000" aria-label="Standardmenge in ml"></div>
                <div class="unit c-abv" data-unit="%"><input type="number" name="drink_abv" value="5" min="0" max="96" step="0.1" aria-label="Alkoholgehalt in Vol-%"></div>
                <div class="unit c-score" data-unit="×"><input type="number" name="drink_score" value="1" min="0" step="0.1" aria-label="Punkte-Faktor"></div>
                <div class="drink-actions">
                    <button class="btn btn-primary btn-inline" title="Getränk hinzufügen"><i class="fa fa-plus"></i></button>
                </div>
            </form>
        </div>
        <div class="template-row">
            <small>Vorlage:</small>
            <select id="drink-template" aria-label="Vorlage für neues Getränk">
                <option value="">– auswählen –</option>
                <?php foreach (DRINK_TEMPLATES as [$name, $ml, $abv, $score]): ?>
                    <option data-ml="<?php echo (int) $ml; ?>" data-abv="<?php echo e($abv); ?>" data-score="<?php echo e($score); ?>"><?php echo e($name); ?></option>
                <?php endforeach; ?>
            </select>
            <small>Menge & Alkohol sind Standardwerte – Gäste können sie beim Einchecken anpassen. Punkte: 1 = normal, 2 = doppelt.</small>
        </div>

        <div class="teams-row">
            <strong style="margin-right:4px;"><i class="fa fa-people-group" style="color:var(--primary);"></i> Teams</strong>
            <?php foreach ($teams as $t): ?>
                <form method="post" class="chip">
                    <?php echo csrfField(); ?>
                    <?php echo e($t['name']); ?> <small><?php echo (int) $t['members']; ?></small>
                    <button class="icon-btn danger" name="delete_team_id" value="<?php echo (int) $t['id']; ?>" title="Team löschen" aria-label="Team <?php echo e($t['name']); ?> löschen"
                        onclick="return confirm('Team löschen? Die Mitglieder bleiben ohne Team.')"><i class="fa fa-xmark"></i></button>
                </form>
            <?php endforeach; ?>
            <form method="post" class="row-form">
                <?php echo csrfField(); ?>
                <input type="text" name="team_name" placeholder="Neues Team" maxlength="40" required style="padding:6px 10px; font-size:0.9rem; width:150px;" aria-label="Name des neuen Teams">
                <button class="icon-btn" name="add_team" value="1" title="Team anlegen" aria-label="Team anlegen"><i class="fa fa-plus"></i></button>
            </form>
            <small style="flex-basis:100%;">Gäste können unter „Mein Pegel“ einem Team beitreten oder selbst eins gründen.</small>
        </div>
    </div>

    <!-- 7. Team & guests -->
    <div class="card" id="team">
        <h3><i class="fa fa-user-plus"></i> Team & Gäste einladen</h3>
        <form method="post" class="row-form">
            <?php echo csrfField(); ?>
            <input type="email" name="invite_email" placeholder="gast@beispiel.de" required aria-label="E-Mail-Adresse">
            <button class="btn btn-secondary btn-inline">Einladen</button>
        </form>
        <small style="display:block; margin-top:6px;">Bestehende Accounts bekommen sofort Zugriff, alle anderen einen Registrierungs-Link.</small>

        <ul class="people">
            <?php foreach ($members as $m):
                $isCreator = (int) $m['id'] === (int) $event['created_by']; ?>
                <li>
                    <span class="initial"><?php echo e(mb_substr($m['username'], 0, 1)); ?></span>
                    <span class="who"><?php echo e($m['username']); ?> <small><?php echo e($m['email']); ?></small></span>
                    <?php if ($isCreator): ?><span class="role">Ersteller</span><?php endif; ?>
                    <?php if ($canManageTeam && !$isCreator): ?>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <button class="icon-btn danger" name="remove_user_id" value="<?php echo (int) $m['id']; ?>" title="Zugriff entfernen" aria-label="<?php echo e($m['username']); ?> entfernen"
                                data-name="<?php echo e($m['username']); ?>" onclick="return confirm('Zugriff für ' + this.dataset.name + ' entfernen?')"><i class="fa fa-user-minus"></i></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php foreach ($invites as $inv): ?>
                <li class="pending">
                    <span class="initial"><i class="fa fa-envelope"></i></span>
                    <span class="who"><?php echo e($inv['email']); ?> <small>(Wartet auf Registrierung...)</small></span>
                    <?php if ($canManageTeam): ?>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <button class="icon-btn danger" name="cancel_invite_id" value="<?php echo (int) $inv['id']; ?>" title="Einladung zurückziehen" aria-label="Einladung zurückziehen"><i class="fa fa-xmark"></i></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- 8. Danger zone -->
    <div class="card" style="border: 1px solid #ff4444; background: rgba(50, 0, 0, 0.3); margin-top: 40px;">
        <h3 style="color: #ff4444;">Danger Zone</h3>
        <p style="color: #ccc;">
            Hier kannst du das gesamte Event unwiderruflich löschen.
            Alle Bilder, Getränke und Statistiken werden dauerhaft entfernt.
        </p>

        <form id="deleteForm" method="post" style="margin-top:20px;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="delete_event_final" value="yes">

            <label style="display:flex; align-items:center; margin-bottom:20px; cursor:pointer; background:rgba(0,0,0,0.3); padding:10px; border-radius:5px;">
                <input type="checkbox" id="backupCheckbox" checked style="width:20px; height:20px; margin:0 10px 0 0;">
                <span style="color:white;">
                    <strong>Backup erstellen & Download abwarten</strong><br>
                    <small style="color:#aaa;">Lädt erst das komplette ZIP herunter. Erst wenn der Download erfolgreich war, wird gelöscht.</small>
                </span>
            </label>

            <div id="loadingStatus" style="display:none; text-align:center; margin-bottom:15px; padding:15px; background:#222; border-radius:5px; border:1px solid #555;">
                <div style="font-size:1.5rem;">⏳</div>
                <div id="loadingText" style="margin-top:5px; font-weight:bold;">Backup wird erstellt...</div>
                <div style="font-size:0.8rem; color:#888;">Bitte Fenster nicht schließen!</div>
            </div>

            <button type="button" id="deleteBtn" data-name="<?php echo e($event['name']); ?>" data-uuid="<?php echo e($uuid); ?>" onclick="initiateDeleteProcess(this.dataset.name, this.dataset.uuid)" class="btn btn-danger" style="width: 100%; font-size: 1.1rem; padding: 15px;">
                ⚠️ EVENT "<?php echo e($event['name']); ?>" LÖSCHEN
            </button>
        </form>
    </div>

</div>

<div id="qrModal" class="modal-overlay" onclick="this.style.display='none'">
    <div class="modal-content">
        <img src="<?php echo e($qrCode); ?>" alt="QR-Code groß">
        <p class="muted" style="margin-bottom:0;">Zum Schließen tippen</p>
    </div>
</div>
<div id="toast" class="hidden"></div>

<script>
    function toggleRename(edit) {
        document.getElementById('title-view').classList.toggle('hidden', edit);
        document.getElementById('title-edit').classList.toggle('hidden', edit);
        document.getElementById('rename-form').classList.toggle('hidden', !edit);
        if (edit) {
            const input = document.getElementById('eventName');
            input.focus();
            input.select();
        }
    }

    document.getElementById('eventName').addEventListener('keydown', (e) => {
        if (e.key === 'Escape') toggleRename(false);
    });

    function showToast(text) {
        const toast = document.getElementById('toast');
        toast.textContent = text;
        toast.classList.remove('hidden');
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => toast.classList.add('hidden'), 1800);
    }

    async function copyLink() {
        const input = document.getElementById("shareLink");
        try {
            await navigator.clipboard.writeText(input.value);
        } catch (e) {
            // Clipboard API is only available on HTTPS: fall back to the selection.
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
        }
        showToast('Link kopiert ✓');
    }

    document.getElementById('drink-template').addEventListener('change', (e) => {
        const option = e.target.selectedOptions[0];
        if (!option.value) return;
        const form = document.getElementById('add-drink');
        form.drink_name.value = option.value;
        form.drink_volume.value = option.dataset.ml;
        form.drink_abv.value = option.dataset.abv;
        form.drink_score.value = option.dataset.score;
        form.drink_name.focus();
        e.target.value = '';
    });

    async function initiateDeleteProcess(eventName, eventUuid) {
        const check = prompt("SICHERHEITS-CHECK:\nDas Löschen kann NICHT rückgängig gemacht werden.\n\nBitte tippe den Namen des Events ab um fortzufahren:\n" + eventName);

        if (check !== eventName) {
            alert("Name stimmte nicht überein. Abbruch.");
            return;
        }

        const form = document.getElementById('deleteForm');
        const backupChecked = document.getElementById('backupCheckbox').checked;
        const statusBox = document.getElementById('loadingStatus');
        const statusText = document.getElementById('loadingText');
        const btn = document.getElementById('deleteBtn');

        btn.disabled = true;
        btn.style.opacity = "0.5";

        if (backupChecked) {
            try {
                statusBox.style.display = 'block';
                statusText.innerText = "1/3: Erstelle ZIP auf dem Server...";

                const response = await fetch('download_zip.php?event=' + encodeURIComponent(eventUuid) + '&export_db=1');

                if (!response.ok) {
                    throw new Error("Fehler beim Erstellen des Backups. Server antwortete mit " + response.status);
                }

                statusText.innerText = "2/3: Lade Datei herunter...";

                const blob = await response.blob();

                const downloadUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = downloadUrl;
                a.download = "Backup_" + eventName.replace(/[^a-z0-9]/gi, '_') + ".zip";
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(downloadUrl);

                statusText.innerText = "3/3: Download fertig! Lösche Event...";
                statusText.style.color = "#ff4444";

                setTimeout(() => {
                    form.submit();
                }, 500);

            } catch (error) {
                alert("FEHLER: " + error.message + "\n\nDas Event wurde NICHT gelöscht, da das Backup fehlgeschlagen ist.");
                statusBox.style.display = 'none';
                btn.disabled = false;
                btn.style.opacity = "1";
            }
        } else {
            form.submit();
        }
    }
</script>
</body>

</html>
