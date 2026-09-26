<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';

$msg = "";
$msgClass = "";

$inviteToken = (string) ($_GET['invite'] ?? ($_POST['invite_token'] ?? ''));

/** Returns the event UUID of a valid, non-expired invite for this e-mail, or null. */
function findValidInvite(mysqli $conn, string $token, string $email): ?string
{
    if ($token === '') {
        return null;
    }
    $days = INVITE_TTL_DAYS;
    $stmt = $conn->prepare(
        "SELECT event_uuid FROM event_invites WHERE token = ? AND email = ? AND created_at > (NOW() - INTERVAL ? DAY)"
    );
    $stmt->bind_param("ssi", $token, $email, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['event_uuid'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $displayName = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $code = (string) ($_POST['reg_code'] ?? '');

    $inviteEventUuid = findValidInvite($conn, $inviteToken, $email);
    $codeValid = REGISTRATION_CODE !== '' && hash_equals(REGISTRATION_CODE, $code);

    if (!$inviteEventUuid && !$codeValid) {
        $msg = $inviteToken !== ''
            ? "Einladung ungültig, abgelaufen oder für eine andere E-Mail-Adresse."
            : "Falscher Registrierungs-Code!";
        $msgClass = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Ungültige E-Mail.";
        $msgClass = "error";
    } elseif ($displayName === '' || mb_strlen($displayName) > 20) {
        $msg = "Der Anzeigename muss 1–20 Zeichen lang sein.";
        $msgClass = "error";
    } elseif (mb_strlen($pass) < PASSWORD_MIN_LENGTH) {
        $msg = "Das Passwort muss mindestens " . PASSWORD_MIN_LENGTH . " Zeichen lang sein.";
        $msgClass = "error";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $email, $displayName);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            $msg = "User existiert bereits (E-Mail oder Anzeigename).";
            $msgClass = "error";
        } else {
            $verifyToken = bin2hex(random_bytes(32));
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, source, verify_token, is_verified) VALUES (?, ?, ?, 'local', ?, 0)");
                $stmt->bind_param("ssss", $displayName, $email, $hash, $verifyToken);
                $stmt->execute();
                $newUserId = $conn->insert_id;

                if ($inviteEventUuid) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO event_users (event_uuid, user_id) VALUES (?, ?)");
                    $stmt->bind_param("si", $inviteEventUuid, $newUserId);
                    $stmt->execute();

                    $stmt = $conn->prepare("DELETE FROM event_invites WHERE token = ?");
                    $stmt->bind_param("s", $inviteToken);
                    $stmt->execute();
                }
                $conn->commit();

                if (sendVerificationMail($email, $verifyToken)) {
                    $msg = "Account erstellt! 📩 Bitte E-Mail bestätigen.";
                    $msgClass = "success";
                } else {
                    $msg = "Account erstellt, aber Mail-Fehler.";
                    $msgClass = "error";
                }
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log('PicDrop: registration failed: ' . $e->getMessage());
                $msg = "DB Fehler.";
                $msgClass = "error";
            }
        }
    }
}

$pageTitle = "Registrieren";
require __DIR__ . '/lib/header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:350px; text-align:center;">
        <h2>Konto erstellen</h2>

        <?php if ($inviteToken): ?>
            <div style="background:rgba(0,255,136,0.1); border:1px solid #00ff88; color:#00ff88; padding:10px; border-radius:5px; margin-bottom:15px; font-size:0.9rem;">
                Du wurdest eingeladen! Erstelle jetzt deinen Account.
            </div>
        <?php endif; ?>

        <?php echo renderMessage($msg, $msgClass); ?>

        <?php if ($msgClass !== 'success'): ?>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="invite_token" value="<?php echo e($inviteToken); ?>">

                <input type="email" name="email" placeholder="E-Mail Adresse" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                <input type="text" name="username" placeholder="Anzeigename" required maxlength="20">
                <input type="password" name="password" placeholder="Passwort (mind. <?php echo PASSWORD_MIN_LENGTH; ?> Zeichen)" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">

                <?php if (!$inviteToken): ?>
                    <input type="text" name="reg_code" placeholder="Registrierungs-Code" required>
                <?php endif; ?>

                <button class="btn btn-primary" style="margin-top:10px;">Registrieren</button>
            </form>
        <?php endif; ?>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="login.php">Login</a>
        </p>
    </div>
</div>
</body>

</html>