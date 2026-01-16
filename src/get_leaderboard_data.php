<?php
require_once 'auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$eventId = $_GET['event'] ?? '';
if (!$eventId) {
    echo json_encode([]);
    exit;
}

checkEventAccess($conn, $eventId);

$evtQuery = $conn->prepare("SELECT setting_merge_by_device FROM events WHERE uuid = ?");
$evtQuery->bind_param("s", $eventId);
$evtQuery->execute();
$mergeByDevice = $evtQuery->get_result()->fetch_assoc()['setting_merge_by_device'] ?? 1;

$users = [];
$details = [];

if ($mergeByDevice == 1) {
    $sqlUsers = "
        SELECT 
            COALESCE(device_uuid, uploader_name) as unique_id,
            SUBSTRING_INDEX(GROUP_CONCAT(uploader_name ORDER BY timestamp DESC SEPARATOR '|||'), '|||', 1) as display_name,
            COUNT(*) as count
        FROM uploads
        WHERE event_id = ? AND drink_id IS NOT NULL AND uploader_name != ''
        GROUP BY unique_id
        ORDER BY count DESC LIMIT 30
    ";

    $sqlDetails = "
        SELECT 
            SUBSTRING_INDEX(GROUP_CONCAT(u.uploader_name ORDER BY u.timestamp DESC SEPARATOR '|||'), '|||', 1) as current_name,
            COALESCE(u.device_uuid, u.uploader_name) as unique_id,
            d.name, d.image_path, COUNT(*) as qty
        FROM uploads u
        JOIN drinks d ON u.drink_id = d.id
        WHERE u.event_id = ? AND u.uploader_name != ''
        GROUP BY unique_id, d.name
        ORDER BY unique_id, qty DESC
    ";

    $stmt = $conn->prepare($sqlUsers);
    $stmt->bind_param("s", $eventId);
    $stmt->execute();
    $resUsers = $stmt->get_result();
    while ($row = $resUsers->fetch_assoc()) {
        $users[] = [
            'uploader_name' => $row['display_name'],
            'count' => $row['count']
        ];
    }

    $stmtD = $conn->prepare($sqlDetails);
    $stmtD->bind_param("s", $eventId);
    $stmtD->execute();
    $resDetails = $stmtD->get_result();
    while ($row = $resDetails->fetch_assoc()) {
        $details[$row['current_name']][] = [
            'drink' => $row['name'],
            'img' => $row['image_path'],
            'qty' => $row['qty']
        ];
    }
} else {
    $resUsers = $conn->query("
        SELECT uploader_name, COUNT(*) as count 
        FROM uploads 
        WHERE event_id = '$eventId' AND drink_id IS NOT NULL AND uploader_name != '' 
        GROUP BY uploader_name ORDER BY count DESC LIMIT 30
    ");
    while ($row = $resUsers->fetch_assoc()) {
        $users[] = $row;
    }

    $resDetails = $conn->query("
        SELECT u.uploader_name, d.name, d.image_path, COUNT(*) as qty
        FROM uploads u
        JOIN drinks d ON u.drink_id = d.id
        WHERE u.event_id = '$eventId' AND u.uploader_name != ''
        GROUP BY u.uploader_name, d.name
        ORDER BY u.uploader_name, qty DESC
    ");
    while ($row = $resDetails->fetch_assoc()) {
        $details[$row['uploader_name']][] = [
            'drink' => $row['name'],
            'img' => $row['image_path'],
            'qty' => $row['qty']
        ];
    }
}

$drinks = [];
$resDrinks = $conn->query("
    SELECT d.name, d.image_path, COUNT(*) as count 
    FROM uploads u JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = '$eventId' 
    GROUP BY d.id ORDER BY count DESC
");
while ($row = $resDrinks->fetch_assoc()) {
    $drinks[] = $row;
}

echo json_encode([
    'users' => $users,
    'details' => $details,
    'drinks' => $drinks
]);
