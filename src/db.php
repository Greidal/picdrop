<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'photobooth';
$bootstrapUser = getenv('DB_BOOTSTRAP_USER') ?: $user;
$bootstrapPass = getenv('DB_BOOTSTRAP_PASS') ?: $pass;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/bootstrap.php';

function picdropCreateDatabaseIfNeeded(string $host, string $user, string $pass, string $db): void
{
    $bootstrapConnection = new mysqli($host, $user, $pass);
    $bootstrapConnection->set_charset('utf8mb4');
    $bootstrapConnection->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $bootstrapConnection->close();
}

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1049) {
        try {
            picdropCreateDatabaseIfNeeded($host, $bootstrapUser, $bootstrapPass, $db);
            $conn = new mysqli($host, $user, $pass, $db);
            $conn->set_charset("utf8mb4");
        } catch (Exception $bootstrapException) {
            die("Error when creating database: " . $bootstrapException->getMessage());
        }
    } else {
        die("Error when connecting to database: " . $e->getMessage());
    }
}

try {
    picdropEnsureSchema($conn);
} catch (Exception $e) {
    die("Error when initializing database structure: " . $e->getMessage());
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
