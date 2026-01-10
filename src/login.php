<?php
require 'db.php';
session_start();
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, is_admin, is_verified FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            if ($row['is_verified'] == 0) {
                $msg = "Bitte bestätige erst deine E-Mail-Adresse! 📧";
            } else {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['is_admin'] = $row['is_admin'];

                header("Location: admin.php");
                exit;
            }
        } else {
            $msg = "Ungültige Zugangsdaten.";
        }
    } else {
        $msg = "Ungültige Zugangsdaten.";
    }
}
require 'header.php';
?>

<div style="height:100vh; display:flex; justify-content:center; align-items:center;">
    <div class="card" style="width:300px; text-align:center;">
        <h2>Login 🔐</h2>

        <?php if ($msg): ?>
            <p class='msg error'><?php echo htmlspecialchars($msg); ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="email" name="email" placeholder="E-Mail Adresse" required autofocus>
            <input type="password" name="password" placeholder="Passwort" required>
            <button class="btn btn-primary" style="margin-top:10px;">Einloggen</button>
        </form>

        <p style="margin-top:20px; font-size:0.9rem;">
            <a href="register.php">Noch keinen Account?</a>
        </p>
    </div>
</div>

</body>

</html>