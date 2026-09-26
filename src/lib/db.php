<?php
require_once __DIR__ . '/helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function picdropConnect(bool $withDatabase = true): mysqli
{
    $conn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        $withDatabase ? (getenv('DB_NAME') ?: 'photobooth') : null,
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $conn->set_charset('utf8mb4');
    return $conn;
}

if (PHP_SAPI !== 'cli') {
    try {
        $conn = picdropConnect();
    } catch (mysqli_sql_exception $e) {
        error_log('PicDrop: database connection failed: ' . $e->getMessage());
        http_response_code(503);
        die("Die Datenbank ist gerade nicht erreichbar. Bitte später erneut versuchen.");
    }
}

function getEventOrDie(mysqli $conn, $uuid): string
{
    if (!isValidUuid($uuid)) {
        http_response_code(404);
        die("⛔ Keine gültige Event-ID angegeben.");
    }
    $stmt = $conn->prepare("SELECT name FROM events WHERE uuid = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        http_response_code(404);
        die("⛔ Event nicht gefunden oder ungültig.");
    }
    return $row['name'];
}
