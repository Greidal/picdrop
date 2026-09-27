<?php
require_once __DIR__ . '/lib/auth.php';
$msg = "";
$success = false;

if (isset($_GET['token']) && is_string($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE verify_token = ? AND is_verified = 0
         AND (verify_expires_at IS NULL OR verify_expires_at > NOW())"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        $uid = $user['id'];

        $upd = $conn->prepare("UPDATE users SET is_verified = 1, verify_token = NULL, verify_expires_at = NULL WHERE id = ?");
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
require __DIR__ . '/lib/header.php';
?>
<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card text-center" style="width:300px;">
        <h2><?php echo $success ? "Juhu!" : "Oje..."; ?></h2>
        <p class="msg <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo e($msg); ?>
        </p>
        <a href="login.php" class="btn btn-primary">Zum Login</a>
        <?php if (!$success): ?>
            <p style="margin-top:20px; font-size:0.9rem;"><a href="resend_verification.php">Neuen Bestätigungslink anfordern</a></p>
        <?php endif; ?>
    </div>
</div>
</body>

</html>