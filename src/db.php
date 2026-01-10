<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'photobooth';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Fehler bei der Verbindung zur Datenbank: " . $e->getMessage());
}

function getEventOrDie($conn, $uuid)
{
    if (empty($uuid)) {
        die("⛔ Keine Event-ID angegeben.");
    }
    $stmt = $conn->prepare("SELECT name FROM events WHERE uuid = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        die("⛔ Event nicht gefunden oder ungültig.");
    }
    return $res->fetch_assoc()['name'];
}
