<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';
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

$evt = $conn->prepare("SELECT setting_show_bar, setting_show_bac FROM events WHERE uuid = ?");
$evt->bind_param("s", $eventId);
$evt->execute();
$settings = $evt->get_result()->fetch_assoc();
$withBac = !empty($settings['setting_show_bar']) && !empty($settings['setting_show_bac']);

$stmt = $conn->prepare("
    SELECT u.*, UNIX_TIMESTAMP(u.timestamp) AS ts, d.name as drink_name, d.image_path 
    FROM uploads u 
    LEFT JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = ? 
    ORDER BY u.timestamp DESC
");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$result = $stmt->get_result();

// BAC of the photographer at the moment the photo was taken (one curve per device).
$curves = [];
if ($withBac) {
    $profiles = drinkLoadProfiles($conn, $eventId);
    $byDevice = [];
    foreach (drinkLoadCheckins($conn, $eventId) as $c) {
        if ($c['device'] !== null) {
            $byDevice[$c['device']][] = $c;
        }
    }
    foreach ($byDevice as $device => $drinks) {
        $curves[$device] = bacCurve($drinks, $profiles[$device] ?? drinkDefaultProfile(), time());
    }
}

$images = [];
while ($row = $result->fetch_assoc()) {
    $bac = null;
    if ($withBac && isset($curves[$row['device_uuid']])) {
        $bac = round(bacAt($curves[$row['device_uuid']], (int) $row['ts']), 2);
    }
    $images[] = [
        'file' => $row['filename'],
        'src' => imageVariantUrl($eventId, $row['filename'], 'display'),
        'timestamp' => (int) $row['ts'],
        'bac' => $bac,
        'uploader' => $row['uploader_name'],
        'drink_name' => $row['drink_name'],
        'is_drink' => !empty($row['drink_id'])
    ];
}

echo json_encode($images);
