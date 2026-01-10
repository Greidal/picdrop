<?php
require_once 'auth.php';
require_once 'db.php';

requireLogin();

$userId = getCurrentUserId();
$username = $_SESSION['username'];
$isAdmin = isAdmin();

$flash = getFlashMessage();
$msg = $flash ? $flash['text'] : "";
$msgClass = $flash ? $flash['type'] : "";

function gen_uuid()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_name'])) {
    $name = htmlspecialchars(trim($_POST['create_name']));

    if ($name) {
        $uuid = gen_uuid();

        $stmt = $conn->prepare("INSERT INTO events (uuid, name) VALUES (?, ?)");
        $stmt->bind_param("ss", $uuid, $name);

        if ($stmt->execute()) {
            $stmtUser = $conn->prepare("INSERT INTO event_users (event_uuid, user_id) VALUES (?, ?)");
            $stmtUser->bind_param("si", $uuid, $userId);
            $stmtUser->execute();

            setFlashMessage("Event '$name' erfolgreich erstellt!", "success");
            header("Location: admin.php");
            exit;
        } else {
            $msg = "Datenbank-Fehler: " . $conn->error;
            $msgClass = "error";
        }
    } else {
        $msg = "Bitte einen Namen eingeben.";
        $msgClass = "error";
    }
}

if ($isAdmin) {
    $sql = "SELECT e.*, (SELECT COUNT(*) FROM uploads WHERE event_id = e.uuid) as img_count 
            FROM events e 
            ORDER BY e.created_at DESC";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT e.*, (SELECT COUNT(*) FROM uploads WHERE event_id = e.uuid) as img_count 
            FROM events e 
            JOIN event_users eu ON e.uuid = eu.event_uuid 
            WHERE eu.user_id = ? 
            ORDER BY e.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
}

$stmt->execute();
$events = $stmt->get_result();

$pageTitle = "Dashboard";
require 'header.php';
?>

<div class="container">
    <div class="flex-between" style="margin-bottom:30px; border-bottom:1px solid #333; padding-bottom:10px;">
        <div>
            Hi, <strong><?php echo htmlspecialchars($username); ?></strong> 👋
            <?php if ($isAdmin): ?>
                <span style="background:#ff0055; padding:2px 8px; border-radius:4px; font-size:0.8rem; margin-left:10px;">ADMIN</span>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
    </div>

    <?php if ($msg): ?>
        <div class="msg <?php echo $msgClass ?: 'success'; ?>">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>➕ Neues Event erstellen</h3>
        <form method="post" style="display:flex; gap:10px;">
            <input type="text" name="create_name" placeholder="Name (z.B. Lukas Geburtstag)" required style="margin:0;">
            <button type="submit" class="btn btn-primary" style="width:auto;">Erstellen</button>
        </form>
    </div>

    <h3>Deine Events</h3>
    <?php if ($events->num_rows === 0): ?>
        <p style="color:#888; font-style:italic;">Noch keine Events vorhanden.</p>
    <?php endif; ?>

    <?php while ($row = $events->fetch_assoc()): ?>
        <div class="card">
            <div class="flex-between">
                <div>
                    <h2 style="margin-bottom:5px;"><?php echo htmlspecialchars($row['name']); ?></h2>
                    <div style="color:#888; font-size:0.9rem;">
                        Fotos: <strong style="color:white"><?php echo $row['img_count']; ?></strong>
                    </div>
                </div>
                <div>
                    <a href="manage_event.php?event=<?php echo $row['uuid']; ?>" class="btn btn-secondary btn-small">⚙️ Verwalten</a>
                </div>
            </div>

            <hr style="border:0; border-top:1px solid #333; margin:15px 0;">

            <div class="grid">
                <a href="index.php?event=<?php echo $row['uuid']; ?>" target="_blank" class="btn btn-primary btn-small">📸 Anwendungslink</a>
                <a href="slideshow.php?event=<?php echo $row['uuid']; ?>" target="_blank" class="btn btn-secondary btn-small">📺 Slideshow</a>
                <a href="leaderboard.php?event=<?php echo $row['uuid']; ?>" target="_blank" class="btn btn-secondary btn-small">🏆 Leaderboard</a>
                <a href="gallery.php?event=<?php echo $row['uuid']; ?>" class="btn btn-secondary btn-small">🖼️ Galerie</a>
                <a href="download_zip.php?event=<?php echo $row['uuid']; ?>&export_db=1" class="btn btn-secondary btn-small">📦 ZIP-Download</a>
            </div>
        </div>
    <?php endwhile; ?>
</div>
</body>

</html>