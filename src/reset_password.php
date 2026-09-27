<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/account.php';

$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$user = findUserByResetToken($conn, $token);
$msg = "";
$msgClass = "";
$done = false;

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $pass = (string) ($_POST['password'] ?? '');
    $passRepeat = (string) ($_POST['password_repeat'] ?? '');

    if (mb_strlen($pass) < PASSWORD_MIN_LENGTH) {
        $msg = "Das Passwort muss mindestens " . PASSWORD_MIN_LENGTH . " Zeichen lang sein.";
        $msgClass = "error";
    } elseif (!hash_equals($pass, $passRepeat)) {
        $msg = "Die Passwörter stimmen nicht überein.";
        $msgClass = "error";
    } else {
        resetPassword($conn, (int) $user['id'], $pass);
        clearFailedLogins($conn, mb_strtolower($user['email']));
        $msg = "Dein Passwort wurde geändert. Du kannst dich jetzt einloggen.";
        $msgClass = "success";
        $done = true;
    }
}

$pageTitle = "Neues Passwort";
require __DIR__ . '/lib/header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:320px; text-align:center;">
        <h2>Neues Passwort 🔐</h2>

        <?php if (!$user && !$done): ?>
            <?php echo renderMessage("Dieser Link ist ungültig, abgelaufen oder wurde schon verwendet.", "error"); ?>
            <p style="font-size:0.9rem;"><a href="forgot_password.php">Neuen Link anfordern</a></p>
        <?php else: ?>
            <?php echo renderMessage($msg, $msgClass); ?>

            <?php if (!$done): ?>
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <input type="password" name="password" placeholder="Neues Passwort (mind. <?php echo PASSWORD_MIN_LENGTH; ?> Zeichen)" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" autofocus>
                    <input type="password" name="password_repeat" placeholder="Passwort wiederholen" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                    <button class="btn btn-primary" style="margin-top:10px;">Passwort speichern</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="login.php">Zum Login</a>
        </p>
    </div>
</div>
</body>

</html>
