<?php
require_once 'auth.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$uuid = $_GET['event'] ?? '';

if (!$uuid) exit;

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST['emoji'] ?? '';

    $input = trim($input);

    if (mb_strlen($input) > 0 && mb_strlen($input) <= 4) {
        try {
            $stmt = $conn->prepare("INSERT INTO live_reactions (event_uuid, emoji) VALUES (?, ?)");
            $stmt->bind_param("ss", $uuid, $input);
            $stmt->execute();
            echo json_encode(['status' => 'ok']);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid Event']);
        }
    } else {
        echo json_encode(['status' => 'ignored', 'msg' => 'Too long or empty']);
    }
    exit;
}

if ($action === 'poll') {
    $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

    $sql = "SELECT id, emoji FROM live_reactions 
            WHERE event_uuid = ? AND id > ? AND created_at > (NOW() - INTERVAL 10 SECOND)
            ORDER BY id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $uuid, $lastId);
    $stmt->execute();
    $res = $stmt->get_result();

    $reactions = [];
    while ($row = $res->fetch_assoc()) {
        $reactions[] = $row;
    }

    echo json_encode($reactions);

    if (rand(1, 100) === 1) {
        $conn->query("DELETE FROM live_reactions WHERE created_at < (NOW() - INTERVAL 5 MINUTE)");
    }
    exit;
}
