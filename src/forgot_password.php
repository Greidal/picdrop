<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/account.php';

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isAccountMailThrottled($conn, $email)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $token = issuePasswordResetToken($conn, (int) $user['id']);
            logAccountMail($conn, $email, 'password_reset');
            sendPasswordResetMail($email, $token);
        }
    }
    // Same answer whether or not the account exists, so addresses can't be probed.
    $sent = true;
}

$pageTitle = "Passwort vergessen";
require __DIR__ . '/lib/header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:320px; text-align:center;">
        <h2>Passwort vergessen 🔑</h2>

        <?php if ($sent): ?>
            <?php echo renderMessage("Falls ein Account mit dieser Adresse existiert, haben wir dir einen Link zum Zurücksetzen geschickt. Er ist " . PASSWORD_RESET_TTL_MINUTES . " Minuten gültig."); ?>
        <?php else: ?>
            <p style="color:#888; font-size:0.9rem;">Gib deine E-Mail-Adresse ein. Du bekommst einen Link, mit dem du ein neues Passwort festlegen kannst.</p>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="email" name="email" placeholder="E-Mail Adresse" required autofocus>
                <button class="btn btn-primary" style="margin-top:10px;">Link anfordern</button>
            </form>
        <?php endif; ?>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="login.php">Zurück zum Login</a>
        </p>
    </div>
</div>
</body>

</html>
