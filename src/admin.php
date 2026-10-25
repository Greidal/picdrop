<?php
require_once __DIR__ . '/lib/auth.php';

requireLogin();

$userId = getCurrentUserId();
$username = $_SESSION['username'];
$isAdmin = isAdmin();

$flash = getFlashMessage();
$msg = $flash ? $flash['text'] : "";
$msgClass = $flash ? $flash['type'] : "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_name'])) {
    requireCsrf();
    $name = trim((string) $_POST['create_name']);

    if ($name === '' || mb_strlen($name) > 100) {
        $msg = "Bitte einen Namen mit 1–100 Zeichen eingeben.";
        $msgClass = "error";
    } else {
        $uuid = generateUuidV4();

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO events (uuid, name, created_by) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $uuid, $name, $userId);
        $stmt->execute();

        $stmtUser = $conn->prepare("INSERT INTO event_users (event_uuid, user_id) VALUES (?, ?)");
        $stmtUser->bind_param("si", $uuid, $userId);
        $stmtUser->execute();
        $conn->commit();

        setFlashMessage("Event '$name' erfolgreich erstellt!", "success");
        header("Location: admin.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['archive_event']) || isset($_POST['unarchive_event']))) {
    requireCsrf();
    $archive = isset($_POST['archive_event']);
    $uuid = (string) ($archive ? $_POST['archive_event'] : $_POST['unarchive_event']);
    checkEventAccess($conn, $uuid);

    if (!canManageEventTeam($conn, $uuid)) {
        setFlashMessage("Nur wer das Event erstellt hat, kann es archivieren.", "error");
    } else {
        $stmt = $conn->prepare($archive
            ? "UPDATE events SET archived_at = NOW() WHERE uuid = ?"
            : "UPDATE events SET archived_at = NULL WHERE uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        setFlashMessage($archive ? "Event archiviert." : "Event wiederhergestellt.", "success");
    }
    header("Location: admin.php");
    exit;
}

$sql = "SELECT e.uuid, e.name, e.created_by, e.archived_at, u.username AS creator,
            (SELECT COUNT(*) FROM uploads WHERE event_id = e.uuid) AS img_count,
            EXISTS(SELECT 1 FROM event_users eu WHERE eu.event_uuid = e.uuid AND eu.user_id = ?) AS is_member
        FROM events e
        LEFT JOIN users u ON u.id = e.created_by "
    . ($isAdmin ? "" : "WHERE EXISTS(SELECT 1 FROM event_users eu WHERE eu.event_uuid = e.uuid AND eu.user_id = ?) ")
    . "ORDER BY e.created_at DESC";
$stmt = $conn->prepare($sql);
if ($isAdmin) {
    $stmt->bind_param("i", $userId);
} else {
    $stmt->bind_param("ii", $userId, $userId);
}
$stmt->execute();

$sections = [
    'mine' => ['title' => 'Von mir erstellt', 'icon' => 'fa-user', 'events' => [], 'open' => true],
    'shared' => ['title' => 'Mir freigegeben', 'icon' => 'fa-user-group', 'events' => [], 'open' => true],
    'others' => ['title' => 'Von anderen Nutzern', 'icon' => 'fa-users', 'events' => [], 'open' => false],
    'archived' => ['title' => 'Archiviert', 'icon' => 'fa-box-archive', 'events' => [], 'open' => false],
];
foreach ($stmt->get_result() as $row) {
    $isOwn = (int) $row['created_by'] === $userId;
    $row['can_archive'] = $isOwn || $isAdmin;
    $row['is_own'] = $isOwn;
    if ($row['archived_at'] !== null) {
        $key = 'archived';
    } elseif ($isOwn) {
        $key = 'mine';
    } elseif ($row['is_member']) {
        $key = 'shared';
    } else {
        $key = 'others';
    }
    $sections[$key]['events'][] = $row;
}
if (!$isAdmin) {
    unset($sections['others']);
}

$pageTitle = "Dashboard";
require __DIR__ . '/lib/header.php';
?>

<style>
    .dash-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }

    .admin-pill {
        background: var(--primary);
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        margin-left: 8px;
    }

    .create-form {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
    }

    .create-form input {
        margin: 0;
    }

    .list-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 6px;
    }

    .list-head h3 {
        margin: 0;
        white-space: nowrap;
    }

    .search {
        position: relative;
        flex: 1;
        max-width: 320px;
        margin-left: auto;
    }

    .search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #666;
        font-size: 0.9rem;
    }

    .search input {
        margin: 0;
        padding: 9px 12px 9px 34px;
        border-radius: 20px;
        font-size: 0.95rem;
    }

    .event-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        grid-template-areas: "main links tools";
        align-items: center;
        gap: 6px 14px;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 10px 12px 16px;
        margin-top: 10px;
    }

    .ev-main {
        grid-area: main;
        min-width: 0;
    }

    .ev-main h2 {
        font-size: 1.15rem;
        font-weight: 500;
        margin: 0 0 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ev-meta {
        color: var(--text-muted);
        font-size: 0.85rem;
    }

    .ev-meta strong {
        color: #fff;
    }

    .ev-links {
        grid-area: links;
        display: flex;
        gap: 2px;
    }

    .ev-tools {
        grid-area: tools;
        display: flex;
        align-items: center;
        border-left: 1px solid var(--border);
        padding-left: 6px;
    }

    .ev-tools form {
        margin: 0;
    }

    .empty {
        color: #666;
        font-style: italic;
        padding: 10px 4px;
        margin: 0;
    }

    @media (max-width: 680px) {
        .event-row {
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas: "main tools" "links links";
            padding: 12px;
        }

        .ev-tools {
            border-left: none;
            padding-left: 0;
        }

        .ev-links {
            justify-content: space-between;
            border-top: 1px solid #262626;
            padding-top: 6px;
        }

        .ev-links .icon-link {
            flex: 1;
        }

        .list-head {
            flex-wrap: wrap;
        }

        .search {
            max-width: none;
            flex: 1 1 100%;
            width: 100%;
            margin-left: 0;
        }
    }
</style>

<div class="container">
    <div class="dash-top">
        <div>
            Hi, <strong><?php echo e($username); ?></strong> 👋
            <?php if ($isAdmin): ?><span class="admin-pill">ADMIN</span><?php endif; ?>
        </div>
        <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
    </div>

    <?php echo renderMessage($msg, $msgClass); ?>

    <form method="post" class="create-form">
        <?php echo csrfField(); ?>
        <input type="text" name="create_name" placeholder="Name (z.B. Lukas Geburtstag)" required maxlength="100" aria-label="Name des neuen Events">
        <button type="submit" class="btn btn-primary btn-inline"><i class="fa fa-plus"></i> Erstellen</button>
    </form>

    <div class="list-head">
        <h3>Deine Events</h3>
        <label class="search">
            <i class="fa fa-search"></i>
            <input type="search" id="event-search" placeholder="Events suchen…" aria-label="Events suchen" autocomplete="off">
        </label>
    </div>
    <p id="no-hits" class="empty hidden">Keine Events gefunden.</p>

    <?php foreach ($sections as $key => $section): ?>
        <details class="section" data-section="<?php echo e($key); ?>" <?php if ($section['open']) echo 'open'; ?>>
            <summary>
                <i class="fa <?php echo e($section['icon']); ?>" style="color:#777; width:18px;"></i>
                <?php echo e($section['title']); ?>
                <span class="count-pill"><?php echo count($section['events']); ?></span>
            </summary>

            <?php if (!$section['events']): ?>
                <p class="empty"><?php echo $key === 'archived' ? 'Keine archivierten Events.' : 'Noch keine Events vorhanden.'; ?></p>
            <?php endif; ?>

            <?php foreach ($section['events'] as $row):
                $q = 'event=' . e($row['uuid']); ?>
                <div class="event-row" data-name="<?php echo e(mb_strtolower($row['name'])); ?>">
                    <div class="ev-main">
                        <h2 title="<?php echo e($row['name']); ?>"><?php echo e($row['name']); ?></h2>
                        <div class="ev-meta">
                            Fotos: <strong><?php echo (int) $row['img_count']; ?></strong>
                            <?php if (!$row['is_own'] && $row['creator'] !== null): ?>
                                · von <?php echo e($row['creator']); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ev-links">
                        <a class="icon-link" href="index.php?<?php echo $q; ?>" target="_blank" title="Anwendungslink öffnen"><i class="fa fa-camera"></i><span>App</span></a>
                        <a class="icon-link" href="slideshow.php?<?php echo $q; ?>" target="_blank" title="Slideshow"><i class="fa fa-tv"></i><span>Slideshow</span></a>
                        <a class="icon-link" href="leaderboard.php?<?php echo $q; ?>" target="_blank" title="Leaderboard"><i class="fa fa-trophy"></i><span>Ranking</span></a>
                        <a class="icon-link" href="gallery.php?<?php echo $q; ?>" title="Galerie"><i class="fa fa-images"></i><span>Galerie</span></a>
                    </div>

                    <div class="ev-tools">
                        <a class="icon-btn" href="manage_event.php?<?php echo $q; ?>" title="Einstellungen" aria-label="Einstellungen"><i class="fa fa-gear"></i></a>
                        <a class="icon-btn" href="download_zip.php?<?php echo $q; ?>&export_db=1" title="ZIP-Download" aria-label="ZIP-Download"><i class="fa fa-download"></i></a>
                        <?php if ($row['can_archive']): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <?php if ($key === 'archived'): ?>
                                    <button class="icon-btn" name="unarchive_event" value="<?php echo e($row['uuid']); ?>" title="Wiederherstellen" aria-label="Wiederherstellen"><i class="fa fa-box-open"></i></button>
                                <?php else: ?>
                                    <button class="icon-btn" name="archive_event" value="<?php echo e($row['uuid']); ?>" title="Archivieren" aria-label="Archivieren"><i class="fa fa-box-archive"></i></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
</div>

<script>
    const sections = document.querySelectorAll('details.section');
    const STORE_KEY = 'picdrop_dashboard_sections';

    // Remember which sections are collapsed.
    try {
        const saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
        sections.forEach((s) => {
            if (s.dataset.section in saved) s.open = saved[s.dataset.section];
        });
    } catch (e) {}
    let searching = false;
    sections.forEach((s) => s.addEventListener('toggle', () => {
        if (searching) return;
        const state = {};
        sections.forEach((x) => state[x.dataset.section] = x.open);
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify(state));
        } catch (e) {}
    }));

    const initialOpen = new Map();
    document.getElementById('event-search').addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();
        if (q && !searching) sections.forEach((s) => initialOpen.set(s, s.open));
        searching = q !== '';

        let total = 0;
        sections.forEach((s) => {
            let hits = 0;
            s.querySelectorAll('.event-row').forEach((row) => {
                const match = !q || row.dataset.name.includes(q);
                row.classList.toggle('hidden', !match);
                if (match) hits++;
            });
            total += hits;
            s.querySelector('.empty')?.classList.toggle('hidden', !!q);
            s.classList.toggle('hidden', !!q && hits === 0);
            s.open = q ? hits > 0 : initialOpen.get(s) ?? s.open;
        });
        document.getElementById('no-hits').classList.toggle('hidden', !q || total > 0);
    });
</script>
</body>

</html>
