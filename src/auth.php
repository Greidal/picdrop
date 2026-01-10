<?php
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function isAdmin()
{
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function getCurrentUserId()
{
    return $_SESSION['user_id'] ?? 0;
}

function checkEventAccess($conn, $uuid)
{
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

function setFlashMessage($text, $type = 'success')
{
    $_SESSION['flash_msg'] = [
        'text' => $text,
        'type' => $type
    ];
}

function getFlashMessage()
{
    if (isset($_SESSION['flash_msg'])) {
        $msg = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg']);
        return $msg;
    }
    return null;
}
