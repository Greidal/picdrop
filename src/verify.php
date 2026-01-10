<?php
require 'db.php';
$msg = "";
$success = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT id FROM users WHERE verify_token = ? AND is_verified = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        $uid = $user['id'];

        $upd = $conn->prepare("UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?");
        $upd->bind_param("i", $uid);

        if ($upd->execute()) {
            $success = true;
            $msg = "Account erfolgreich aktiviert!";
        } else {
            $msg = "Datenbankfehler beim Aktivieren.";
        }
    } else {
        $msg = "Ungültiger oder abgelaufener Link.";
    }
} else {
    header("Location: login.php");
    exit;
}

$pageTitle = "Verifizierung";
require 'header.php';
?>
<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card text-center" style="width:300px;">
        <h2><?php echo $success ? "Juhu!" : "Oje..."; ?></h2>
        <p class="msg <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </p>
        <a href="login.php" class="btn btn-primary">Zum Login</a>
    </div>
</div>
</body>

</html>