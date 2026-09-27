<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/account.php';

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isAccountMailThrottled($conn, $email)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $token = issueVerificationToken($conn, (int) $user['id']);
            logAccountMail($conn, $email, 'verify');
            sendVerificationMail($email, $token);
        }
    }
    // Same answer whether or not the account exists, so addresses can't be probed.
    $sent = true;
}

$pageTitle = "Bestätigungsmail anfordern";
require __DIR__ . '/lib/header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:320px; text-align:center;">
        <h2>Bestätigungsmail 📧</h2>

        <?php if ($sent): ?>
            <?php echo renderMessage("Falls ein noch nicht bestätigter Account mit dieser Adresse existiert, haben wir dir einen neuen Bestätigungslink geschickt."); ?>
        <?php else: ?>
            <p style="color:#888; font-size:0.9rem;">Keine Mail bekommen oder Link abgelaufen? Wir schicken dir einen neuen Bestätigungslink.</p>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="email" name="email" placeholder="E-Mail Adresse" required autofocus>
                <button class="btn btn-primary" style="margin-top:10px;">Link erneut senden</button>
            </form>
        <?php endif; ?>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="login.php">Zurück zum Login</a>
        </p>
    </div>
</div>
</body>

</html>
