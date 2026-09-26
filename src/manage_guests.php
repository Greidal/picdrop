<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';

requireLogin();
$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);
$eventName = getEventOrDie($conn, $uuid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $deviceToBan = (string) ($_POST['ban_device'] ?? '');
    $deviceToUnban = (string) ($_POST['unban_device'] ?? '');
    $deletePhotos = isset($_POST['delete_photos']) && $_POST['delete_photos'] == 1;

    if ($deviceToBan) {
        $stmt = $conn->prepare("INSERT IGNORE INTO blocked_devices (event_uuid, device_uuid) VALUES (?, ?)");
        $stmt->bind_param("ss", $uuid, $deviceToBan);
        $stmt->execute();

        $msg = "Gerät gesperrt.";

        if ($deletePhotos) {
            $sel = $conn->prepare("SELECT filename FROM uploads WHERE event_id = ? AND device_uuid = ?");
            $sel->bind_param("ss", $uuid, $deviceToBan);
            $sel->execute();
            foreach ($sel->get_result() as $row) {
                deleteUploadedImage($uuid, $row['filename']);
            }
            $del = $conn->prepare("DELETE FROM uploads WHERE event_id = ? AND device_uuid = ?");
            $del->bind_param("ss", $uuid, $deviceToBan);
            $del->execute();
            $msg .= " Alle Fotos dieses Geräts wurden gelöscht.";
        }
        setFlashMessage($msg, "success");
    }

    if ($deviceToUnban) {
        $stmt = $conn->prepare("DELETE FROM blocked_devices WHERE event_uuid=? AND device_uuid=?");
        $stmt->bind_param("ss", $uuid, $deviceToUnban);
        $stmt->execute();
        setFlashMessage("Gerät entsperrt.", "success");
    }

    header("Location: manage_guests.php?event=" . urlencode($uuid));
    exit;
}

$sql = "
    SELECT 
        u.device_uuid,
        COUNT(*) as total_uploads,
        MAX(u.timestamp) as last_seen,
        GROUP_CONCAT(DISTINCT u.uploader_name SEPARATOR ', ') as used_names,
        (SELECT COUNT(*) FROM blocked_devices b WHERE b.event_uuid = u.event_id AND b.device_uuid = u.device_uuid) as is_banned
    FROM uploads u
    WHERE u.event_id = ? AND u.device_uuid IS NOT NULL
    GROUP BY u.device_uuid
    ORDER BY total_uploads DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $uuid);
$stmt->execute();
$guests = $stmt->get_result();

$pageTitle = "Gäste verwalten - " . $eventName;
require __DIR__ . '/lib/header.php';
?>

<div class="container">
    <div class="flex-between">
        <h1>👮 Gäste-Management</h1>
        <a href="manage_event.php?event=<?php echo e($uuid); ?>" class="btn btn-secondary btn-small">🔙 Event Einstellungen</a>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash) echo renderMessage($flash['text'], $flash['type']);
    ?>

    <div class="card">
        <p style="color:#ccc;">Hier siehst du alle Geräte, die Fotos hochgeladen haben. Wenn jemand spammt, kannst du ihn hier blockieren.</p>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; color:#eee; text-align:left;">
                <thead>
                    <tr style="border-bottom:1px solid #444;">
                        <th style="padding:10px;">Verwendete Namen</th>
                        <th>Fotos</th>
                        <th>Zuletzt gesehen</th>
                        <th>Status</th>
                        <th style="text-align:right;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($g = $guests->fetch_assoc()): ?>
                        <tr style="border-bottom:1px solid #222;">
                            <td style="padding:10px;">
                                <?php echo e($g['used_names'] ?: '(Ohne Name)'); ?>
                                <br>
                                <small style="color:#555; font-family:monospace;"><?php echo e(mb_substr($g['device_uuid'], 0, 15)); ?>...</small>
                            </td>
                            <td><strong><?php echo (int) $g['total_uploads']; ?></strong></td>
                            <td><?php echo date("d.m. H:i", strtotime($g['last_seen'])); ?></td>
                            <td>
                                <?php if ($g['is_banned']): ?>
                                    <span style="color:#ff4444; font-weight:bold;">GESPERRT</span>
                                <?php else: ?>
                                    <span style="color:#00ff88;">Aktiv</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($g['is_banned']): ?>
                                    <form method="post" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="unban_device" value="<?php echo e($g['device_uuid']); ?>">
                                        <button class="btn btn-secondary btn-small">🔓 Entsperren</button>
                                    </form>
                                <?php else: ?>
                                    <button data-device="<?php echo e($g['device_uuid']); ?>" data-names="<?php echo e($g['used_names']); ?>" onclick="confirmBan(this.dataset.device, this.dataset.names)" class="btn btn-danger btn-small">🚫 Sperren</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="banModal" class="modal-overlay" onclick="if(event.target===this) this.style.display='none'">
    <div class="modal-content" style="text-align:left;">
        <h2 style="color:#ff4444; margin-top:0;">🚫 Gast sperren</h2>
        <p>Willst du den Nutzer <strong id="banName"></strong> wirklich sperren?</p>
        <p>Er wird keine neuen Fotos mehr hochladen können.</p>

        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="ban_device" id="banInput">

            <label style="display:flex; align-items:center; margin:20px 0; cursor:pointer; background:#330000; padding:10px; border-radius:5px;">
                <input type="checkbox" name="delete_photos" value="1" style="width:20px; height:20px; margin:0 10px 0 0;">
                <span style="color:#ffaaaa;">Auch alle bisherigen Fotos dieses Nutzers löschen?</span>
            </label>

            <div class="flex-between">
                <button type="button" onclick="document.getElementById('banModal').style.display='none'" class="btn btn-secondary">Abbrechen</button>
                <button type="submit" class="btn btn-danger">Sperren durchführen</button>
            </div>
        </form>
    </div>
</div>

<script>
    function confirmBan(uuid, names) {
        document.getElementById('banInput').value = uuid;
        document.getElementById('banName').innerText = names || 'Unbekannt';
        document.getElementById('banModal').style.display = 'flex';
    }
</script>
</body>

</html>