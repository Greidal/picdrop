<?php
// CLI entry point: `php src/lib/migrate.php`. Runs on container start (see docker/entrypoint.sh).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/migrations.php';

$log = static function (string $msg): void {
    fwrite(STDOUT, "[migrate] $msg\n");
};
$timeout = (int) (getenv('DB_WAIT_TIMEOUT') ?: 60);
$deadline = time() + $timeout;

while (true) {
    try {
        $conn = picdropConnect();
        break;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1049) {
            // Unknown database: create it (needs a user with CREATE privileges).
            $admin = picdropConnect(false);
            $dbName = str_replace('`', '``', getenv('DB_NAME') ?: 'photobooth');
            $admin->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $admin->close();
            continue;
        }
        if (time() >= $deadline) {
            fwrite(STDERR, "[migrate] Database not reachable after {$timeout}s: {$e->getMessage()}\n");
            exit(1);
        }
        $log('Waiting for database ...');
        sleep(2);
    }
}

try {
    picdropRunMigrations($conn, $log);
    picdropCreateBootstrapAdminUser($conn, $log);
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] FAILED: {$e->getMessage()}\n");
    exit(1);
}
