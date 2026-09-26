<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/metadata.php';

requireLogin();

$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);

$flash = getFlashMessage();
$msg = $flash ? $flash['text'] : "";
$msgClass = $flash ? $flash['type'] : "";

function redirectSelf($uuid)
{
    header("Location: manage_event.php?event=" . urlencode($uuid));
    exit;
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

    if (isset($_POST['update_settings'])) {
        $s_badge = isset($_POST['s_badge']) ? 1 : 0;
        $s_uploader = isset($_POST['s_uploader']) ? 1 : 0;
        $s_time = isset($_POST['s_time']) ? 1 : 0;
        $s_bar = isset($_POST['s_bar']) ? 1 : 0;
        $s_evtname = isset($_POST['s_evtname']) ? 1 : 0;
        $s_merge = isset($_POST['s_merge']) ? 1 : 0;
        $s_strip = isset($_POST['s_strip_location']) ? 1 : 0;
        $s_duration = max(2000, min(600000, intval($_POST['s_duration'] ?? 8000)));

        $prev = $conn->prepare("SELECT setting_strip_location FROM events WHERE uuid = ?");
        $prev->bind_param("s", $uuid);
        $prev->execute();
        $wasStripping = !empty($prev->get_result()->fetch_assoc()['setting_strip_location']);

        $upd = $conn->prepare("UPDATE events SET setting_show_badge=?, setting_show_uploader=?, setting_show_time=?, setting_show_event_name=?, setting_show_bar=?, setting_merge_by_device=?, setting_strip_location=?, setting_slide_duration=? WHERE uuid=?");
        $upd->bind_param("iiiiiiiis", $s_badge, $s_uploader, $s_time, $s_evtname, $s_bar, $s_merge, $s_strip, $s_duration, $uuid);

        if ($upd->execute()) {
            $savedMsg = "Einstellungen gespeichert!";
            if ($s_strip && !$wasStripping) {
                // Newly enabled: clean the photos that were uploaded before.
                $counts = stripLocationFromEvent($conn, $uuid);
                $savedMsg .= " Standortdaten aus {$counts[METADATA_STRIPPED]} vorhandenen Fotos entfernt.";
                if ($counts[METADATA_UNSUPPORTED] + $counts[METADATA_FAILED] > 0) {
                    $savedMsg .= " " . ($counts[METADATA_UNSUPPORTED] + $counts[METADATA_FAILED]) . " Fotos konnten nicht bearbeitet werden (z. B. HEIC).";
                }
            }

            if (isset($_FILES['event_logo']) && $_FILES['event_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $targetFile = storeUploadedImage($_FILES['event_logo'], "uploads/$uuid");

                if ($targetFile !== null) {
                    $logoStmt = $conn->prepare("UPDATE events SET logo_path = ? WHERE uuid = ?");
                    $logoStmt->bind_param("ss", $targetFile, $uuid);
                    $logoStmt->execute();

                    setFlashMessage("$savedMsg Logo hochgeladen!", "success");
                    redirectSelf($uuid);
                }

                $msg = "Fehler beim Upload des Logos (erlaubt: JPG, PNG, WebP, GIF).";
                $msgClass = "error";
            } else {
                setFlashMessage($savedMsg, "success");
                redirectSelf($uuid);
            }
        }
    }

    if (isset($_POST['delete_logo'])) {
        $stmt = $conn->prepare("SELECT logo_path FROM events WHERE uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if ($r && $r['logo_path'] && str_starts_with($r['logo_path'], "uploads/$uuid/") && is_file($r['logo_path'])) {
            unlink($r['logo_path']);
        }
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
            redirectSelf($uuid);
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
                redirectSelf($uuid);
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
                redirectSelf($uuid);
            } else {
                $msg = "Datenbankfehler beim Einladen.";
                $msgClass = "error";
            }
        }
    }

    if (isset($_FILES['drink_image']) && isset($_POST['drink_name'])) {
        $drinkName = mb_substr(trim((string) $_POST['drink_name']), 0, 100);
        $score = isset($_POST['drink_score']) ? floatval($_POST['drink_score']) : 1.0;
        $score = max(0.0, min(999.9, $score));

        $targetFile = $drinkName !== '' ? storeUploadedImage($_FILES['drink_image'], "uploads/$uuid/drinks") : null;

        if ($targetFile !== null) {
            $stmt = $conn->prepare("INSERT INTO drinks (event_uuid, name, image_path, score_factor) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssd", $uuid, $drinkName, $targetFile, $score);
            $stmt->execute();

            setFlashMessage("Getränk hinzugefügt!", "success");
            redirectSelf($uuid);
        } else {
            $msg = "Fehler beim Upload des Bildes (Name angeben; erlaubt: JPG, PNG, WebP, GIF).";
            $msgClass = "error";
        }
    }

    if (isset($_POST['delete_drink_id'])) {
        $did = intval($_POST['delete_drink_id']);

        $stmt = $conn->prepare("SELECT image_path FROM drinks WHERE id=? AND event_uuid=?");
        $stmt->bind_param("is", $did, $uuid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if (str_starts_with($row['image_path'], "uploads/$uuid/") && is_file($row['image_path'])) {
                unlink($row['image_path']);
            }

            $del = $conn->prepare("DELETE FROM drinks WHERE id=?");
            $del->bind_param("i", $did);
            $del->execute();

            setFlashMessage("Getränk gelöscht.", "success");
            redirectSelf($uuid);
        }
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

$appUrl = appBaseUrl() . "/?event=" . urlencode($uuid);
$qrCode = (new chillerlan\QRCode\QRCode())->render($appUrl);
$pageTitle = "Verwalten: " . $event['name'];
require __DIR__ . '/lib/header.php';
?>

<div class="container">
    <div class="flex-between">
        <a href="admin.php" class="btn btn-secondary btn-small">🔙 Dashboard</a>
        <a href="manage_guests.php?event=<?php echo e($uuid); ?>" class="btn btn-primary btn-small">👮 Gäste & Spam verwalten</a>
    </div>

    <h1>⚙️ <?php echo e($event['name']); ?></h1>

    <?php echo renderMessage($msg, $msgClass); ?>

    <div class="card text-center">
        <h3>🔗 Event Teilen</h3>
        <p style="color:#888;">Scannen oder Link senden, damit Gäste Fotos hochladen können.</p>

        <div style="display:flex; justify-content:center; gap:20px; align-items:center; flex-wrap:wrap;">
            <img src="<?php echo e($qrCode); ?>" alt="QR-Code zum Event" width="150" height="150" style="border-radius:10px; border:2px solid white; background:white;">

            <div style="text-align:left;">
                <input type="text" value="<?php echo e($appUrl); ?>" id="shareLink" readonly style="width:250px;">
                <br>
                <button onclick="copyLink()" class="btn btn-primary btn-small"><i class="fa fa-copy"></i> Link kopieren</button>
                <a href="<?php echo e($appUrl); ?>" target="_blank" class="btn btn-secondary btn-small"><i class="fa fa-external-link-alt"></i> Öffnen</a>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Event umbenennen</h3>
        <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
            <?php echo csrfField(); ?>
            <div style="flex:1; min-width:240px;">
                <label for="eventName">Neuer Event-Name</label>
                <input type="text" id="eventName" name="event_name" value="<?php echo e($event['name']); ?>" maxlength="100" required>
            </div>
            <button type="submit" name="rename_event" value="1" class="btn btn-primary btn-small">Umbenennen</button>
        </form>
    </div>

    <div class="card">
        <h3>Slideshow-Einstellungen</h3>
        <div style="margin-top:15px;">
            <label>Event-Logo (oben links, optional)</label>
            <?php if (!empty($event['logo_path'])): ?>
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                    <img src="<?php echo e($event['logo_path']); ?>" style="height:60px; object-fit:contain; border-radius:6px; border:1px solid #333; background:#000;">
                    <form method="post" style="display:inline; margin:0;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="delete_logo" value="1">
                        <button class="btn btn-danger btn-small" style="height:36px;">Logo entfernen</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="update_settings" value="1">

            <div class="grid" style="grid-template-columns: 1fr 1fr;">
                <label class="toggle-switch">
                    <input type="checkbox" name="s_badge" <?php if ($event['setting_show_badge']) echo 'checked'; ?>>
                    <span class="slider"></span> "NEU" Badge anzeigen
                </label>

                <label class="toggle-switch" style="grid-column: 1 / -1;">
                    <input type="checkbox" name="s_merge" <?php if ($event['setting_merge_by_device']) echo 'checked'; ?>>
                    <span class="slider"></span>
                    <span>
                        <strong>Leaderboard intelligent zusammenfassen</strong><br>
                        <small style="color:#888; font-size:0.8em; display:block; margin-top:2px;">
                            Wenn aktiv: Fasst alle Uploads eines Geräts zusammen (Schutz gegen Fake-Namen).<br>
                            Wenn aus: Jeder eingegebene Name zählt separat (Chaos-Modus).
                        </small>
                    </span>
                </label>
                <label class="toggle-switch">
                    <input type="checkbox" name="s_uploader" <?php if ($event['setting_show_uploader']) echo 'checked'; ?>>
                    <span class="slider"></span> Name/Getränk anzeigen
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="s_time" <?php if ($event['setting_show_time']) echo 'checked'; ?>>
                    <span class="slider"></span> Uhrzeit anzeigen
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="s_evtname" <?php if ($event['setting_show_event_name']) echo 'checked'; ?>>
                    <span class="slider"></span> Event-Name anzeigen
                </label>
                <label class="toggle-switch">
                    <input type="checkbox" name="s_bar" <?php if (!empty($event['setting_show_bar'])) echo 'checked'; ?>>
                    <span class="slider"></span> Getränk-Button auf der Upload-Seite anzeigen
                </label>
                <label class="toggle-switch" style="grid-column: 1 / -1;">
                    <input type="checkbox" name="s_strip_location" <?php if (!empty($event['setting_strip_location'])) echo 'checked'; ?>>
                    <span class="slider"></span>
                    <span>
                        <strong>Standortdaten (GPS) aus Fotos entfernen</strong><br>
                        <small style="color:#888; font-size:0.8em; display:block; margin-top:2px;">
                            Entfernt GPS-Koordinaten aus hochgeladenen Fotos, ohne die Bildqualität zu verändern.
                            Beim Aktivieren werden auch bereits hochgeladene Fotos bereinigt. HEIC-Dateien werden nicht unterstützt.
                        </small>
                    </span>
                </label>
            </div>

            <div style="margin-top:15px;">
                <label>Anzeigedauer pro Bild (ms):</label>
                <input type="number" name="s_duration" value="<?php echo (int) $event['setting_slide_duration']; ?>" min="2000" step="500">
            </div>

            <div style="margin-top:15px;">
                <label for="eventLogo">Logo hochladen oder ersetzen</label>
                <input id="eventLogo" type="file" name="event_logo" accept="image/*">
                <small style="color:#888; display:block; margin-top:6px;">Max. 2MB empfohlen. Akzeptiert PNG/JPG.</small>
            </div>

            <button type="submit" class="btn btn-primary btn-small" style="margin-top:15px;">Speichern</button>
        </form>
    </div>

    <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top:20px;">

        <div class="card">
            <h3>Team / Gäste einladen</h3>
            <p style="color:#888; font-size:0.9rem;">Gib eine E-Mail-Adresse ein. Wenn der User existiert, wird er hinzugefügt. Wenn nicht, erhält er einen Registrierungs-Link.</p>

            <form method="post">
                <?php echo csrfField(); ?>
                <input type="email" name="invite_email" placeholder="gast@beispiel.de" required>
                <button class="btn btn-secondary btn-small">Einladen</button>
            </form>

            <h4 style="margin-top:20px; border-bottom:1px solid #333; padding-bottom:5px;">Berechtigte User</h4>
            <ul style="padding-left:20px; color:#ccc;">
                <?php
                $team = $conn->prepare("SELECT u.username, u.email FROM users u JOIN event_users eu ON u.id = eu.user_id WHERE eu.event_uuid = ?");
                $team->bind_param("s", $uuid);
                $team->execute();
                foreach ($team->get_result() as $t) {
                    echo "<li>" . e($t['username']) . " <span style='color:#666; font-size:0.8em'>(" . e($t['email']) . ")</span></li>";
                }
                ?>
            </ul>

            <h4 style="margin-top:20px; border-bottom:1px solid #333; padding-bottom:5px;">Offene Einladungen</h4>
            <ul style="padding-left:20px; color:#888;">
                <?php
                $invStmt = $conn->prepare("SELECT email FROM event_invites WHERE event_uuid = ?");
                $invStmt->bind_param("s", $uuid);
                $invStmt->execute();
                $invites = $invStmt->get_result();
                if ($invites->num_rows > 0) {
                    while ($inv = $invites->fetch_assoc()) {
                        echo "<li>" . e($inv['email']) . " (Wartet auf Registrierung...)</li>";
                    }
                } else {
                    echo "<li style='list-style:none; font-size:0.8rem;'>Keine offenen Einladungen.</li>";
                }
                ?>
            </ul>
        </div>

        <div class="card">
            <h3>Getränkekarte</h3>
            <form method="post" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="text" name="drink_name" placeholder="Name (z.B. Bier)" required>

                <div style="display:flex; gap:10px; align-items:center;">
                    <div style="flex-grow:1;">
                        <input type="file" name="drink_image" accept="image/*" required>
                    </div>
                    <div style="width:100px;">
                        <input type="number" name="drink_score" placeholder="Faktor" value="1.0" step="0.1" title="Bewertungsfaktor (Punkte)" style="margin:10px 0;">
                    </div>
                </div>
                <small style="color:#888; display:block; margin-bottom:10px;">Faktor: 1.0 = Normal, 2.0 = Doppelte Punkte</small>

                <button class="btn btn-primary btn-small">Getränk hinzufügen</button>
            </form>

            <div style="margin-top:20px; display:grid; grid-template-columns:repeat(auto-fill, minmax(80px, 1fr)); gap:10px;">
                <?php
                $drinkStmt = $conn->prepare("SELECT * FROM drinks WHERE event_uuid = ?");
                $drinkStmt->bind_param("s", $uuid);
                $drinkStmt->execute();
                $drinks = $drinkStmt->get_result();
                while ($d = $drinks->fetch_assoc()): ?>
                    <div style="position:relative; text-align:center; background:black; border-radius:5px; padding:5px;">
                        <img src="<?php echo e($d['image_path']); ?>" style="width:100%; height:80px; object-fit:cover; border-radius:5px;">

                        <div style="position:absolute; bottom:5px; right:5px; background:var(--primary); color:white; font-size:0.7rem; padding:2px 5px; border-radius:3px; font-weight:bold;">
                            x<?php echo floatval($d['score_factor']); ?>
                        </div>

                        <form method="post" onsubmit="return confirm('Wirklich löschen?');" style="position:absolute; top:-5px; right:-5px;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete_drink_id" value="<?php echo (int) $d['id']; ?>">
                            <button class="btn-danger" style="border-radius:50%; width:24px; height:24px; padding:0; line-height:24px;">×</button>
                        </form>

                        <small style="display:block; margin-top:5px;"><?php echo e($d['name']); ?></small>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

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

<script>
    function copyLink() {
        var copyText = document.getElementById("shareLink");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        alert("Link kopiert: " + copyText.value);
    }

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