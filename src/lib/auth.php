<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function getCurrentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function loginUser(array $user): void
{
    // New session id on privilege change prevents session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (int) $user['is_admin'];
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function isLoginLocked(mysqli $conn, string $email): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE email = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $minutes = LOGIN_LOCK_MINUTES;
    $stmt->bind_param("si", $email, $minutes);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['c'] >= LOGIN_MAX_ATTEMPTS;
}

function recordFailedLogin(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("INSERT INTO login_attempts (email) VALUES (?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    if (random_int(1, 50) === 1) {
        $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    }
}

function clearFailedLogins(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

function checkEventAccess(mysqli $conn, $uuid): void
{
    if (!isValidUuid($uuid)) {
        http_response_code(404);
        die("⛔ Keine gültige Event-ID angegeben.");
    }
    if (isAdmin()) {
        return;
    }

    $userId = getCurrentUserId();
    $stmt = $conn->prepare("SELECT 1 FROM event_users WHERE event_uuid = ? AND user_id = ?");
    $stmt->bind_param("si", $uuid, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(403);
        die("⛔ Zugriff verweigert.");
    }
}

function setFlashMessage(string $text, string $type = 'success'): void
{
    $_SESSION['flash_msg'] = [
        'text' => $text,
        'type' => $type
    ];
}

function getFlashMessage(): ?array
{
    if (isset($_SESSION['flash_msg'])) {
        $msg = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg']);
        return $msg;
    }
    return null;
}

/** Renders a flash/status message box. Text is always escaped. */
function renderMessage(string $msg, string $type = 'success'): string
{
    if ($msg === '') {
        return '';
    }
    $class = in_array($type, ['success', 'error'], true) ? $type : 'success';
    return '<div class="msg ' . $class . '">' . e($msg) . '</div>';
}
