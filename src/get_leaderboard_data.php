<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/drinks.php';

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
$mergeByDevice = (bool) ($evtQuery->get_result()->fetch_assoc()['setting_merge_by_device'] ?? 1);

$board = drinkBoard($conn, $eventId, $mergeByDevice);

$users = array_map(static fn ($p) => [
    'uploader_name' => $p['name'],
    'count' => $p['points'],
    'drinks' => $p['drinks'],
    'bac' => $p['bac'],
    'team' => $p['team'],
    'details' => $p['details'],
], array_slice($board['people'], 0, 30));

$drinks = [];
$stmtDrinks = $conn->prepare("
    SELECT d.name, d.image_path, COUNT(*) as count
    FROM checkins c JOIN drinks d ON c.drink_id = d.id
    WHERE c.event_uuid = ?
    GROUP BY d.id ORDER BY count DESC
");
$stmtDrinks->bind_param("s", $eventId);
$stmtDrinks->execute();
$resDrinks = $stmtDrinks->get_result();
while ($row = $resDrinks->fetch_assoc()) {
    $drinks[] = $row;
}

echo json_encode([
    'users' => $users,
    'teams' => $board['teams'],
    'drinks' => $drinks
]);
