<?php
require_once __DIR__ . '/lib/auth.php';

if (isLoggedIn()) {
    header("Location: admin.php");
    exit;
}

$msg = "";
$showResendLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (isLoginLocked($conn, $email)) {
        $msg = "Zu viele Fehlversuche. Bitte in " . LOGIN_LOCK_MINUTES . " Minuten erneut versuchen.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, is_admin, is_verified FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        // Always run password_verify so response time doesn't reveal whether the account exists.
        $hash = $row['password'] ?? '$2y$12$7wZ2kgzIkZPEDzScHJg7WeSjsiTq.3pr7iiTQskoE36JLJAmN7Edq';
        $valid = password_verify($password, $hash) && $row !== null;

        if (!$valid) {
            recordFailedLogin($conn, $email);
            $msg = "Ungültige Zugangsdaten.";
        } elseif ($row['is_verified'] == 0) {
            $msg = "Bitte bestätige erst deine E-Mail-Adresse! 📧";
            $showResendLink = true;
        } else {
            clearFailedLogins($conn, $email);
            if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param("si", $newHash, $row['id']);
                $upd->execute();
            }
            loginUser($row);
            header("Location: admin.php");
            exit;
        }
    }
}
require __DIR__ . '/lib/header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:300px; text-align:center;">
        <h2>Login 🔐</h2>

        <?php if ($msg): ?>
            <p class='msg error'><?php echo e($msg); ?></p>
        <?php endif; ?>
        <?php if ($showResendLink): ?>
            <p style="font-size:0.9rem;"><a href="resend_verification.php">Bestätigungsmail erneut senden</a></p>
        <?php endif; ?>

        <form method="post">
            <?php echo csrfField(); ?>
            <input type="email" name="email" placeholder="E-Mail Adresse" required autofocus>
            <input type="password" name="password" placeholder="Passwort" required>
            <button class="btn btn-primary" style="margin-top:10px;">Einloggen</button>
        </form>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="forgot_password.php">Passwort vergessen?</a><br>
            <a href="register.php">Noch keinen Account?</a>
        </p>
    </div>
</div>

</body>

</html>