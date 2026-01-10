<?php
require_once 'db.php';
require_once 'mail_helper.php';
require_once 'config.php';

$msg = "";
$msgClass = "";

$inviteToken = $_GET['invite'] ?? ($_POST['invite_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $displayName = trim($_POST['username']);
    $pass = $_POST['password'];
    $code = $_POST['reg_code'];
    $tokenFromForm = $_POST['invite_token'];

    if ($code !== REGISTRATION_CODE) {
        $msg = "Falscher Registrierungs-Code!";
        $msgClass = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Ungültige E-Mail.";
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

            $stmt = $conn->prepare("INSERT INTO users (username, email, password, source, verify_token, is_verified) VALUES (?, ?, ?, 'local', ?, 0)");
            $stmt->bind_param("ssss", $displayName, $email, $hash, $verifyToken);

            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;

                if (!empty($tokenFromForm)) {
                    $invStmt = $conn->prepare("SELECT event_uuid FROM event_invites WHERE token = ? AND email = ?");
                    $invStmt->bind_param("ss", $tokenFromForm, $email);
                    $invStmt->execute();
                    $invRes = $invStmt->get_result();

                    if ($invRow = $invRes->fetch_assoc()) {
                        $evtUuid = $invRow['event_uuid'];
                        $conn->query("INSERT INTO event_users (event_uuid, user_id) VALUES ('$evtUuid', $newUserId)");
                        $conn->query("DELETE FROM event_invites WHERE token = '$tokenFromForm'");
                    }
                }

                if (sendVerificationMail($email, $verifyToken)) {
                    $msg = "Account erstellt! 📩 Bitte E-Mail bestätigen.";
                    $msgClass = "success";
                } else {
                    $msg = "Account erstellt, aber Mail-Fehler.";
                    $msgClass = "error";
                }
            } else {
                $msg = "DB Fehler.";
                $msgClass = "error";
            }
        }
    }
}

$pageTitle = "Registrieren";
require 'header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:350px; text-align:center;">
        <h2>Konto erstellen</h2>

        <?php if ($inviteToken): ?>
            <div style="background:rgba(0,255,136,0.1); border:1px solid #00ff88; color:#00ff88; padding:10px; border-radius:5px; margin-bottom:15px; font-size:0.9rem;">
                Du wurdest eingeladen! Erstelle jetzt deinen Account.
            </div>
        <?php endif; ?>

        <?php if ($msg): ?><div class="msg <?php echo $msgClass; ?>"><?php echo $msg; ?></div><?php endif; ?>

        <?php if ($msgClass !== 'success'): ?>
            <form method="post">
                <input type="hidden" name="invite_token" value="<?php echo htmlspecialchars($inviteToken); ?>">

                <input type="email" name="email" placeholder="E-Mail Adresse" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                <input type="text" name="username" placeholder="Anzeigename" required maxlength="20">
                <input type="password" name="password" placeholder="Passwort" required>

                <input type="text" name="reg_code" placeholder="Registrierungs-Code" required value="<?php echo $inviteToken ? $REGISTRATION_CODE : ''; ?>">

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