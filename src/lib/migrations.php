<?php
/*
 * Minimal, file-based schema migrations.
 *
 * Migrations live in db/migrations and are named NNNN_description.sql or
 * NNNN_description.php (returning a callable(mysqli): void). Each version runs
 * exactly once and is recorded in `schema_migrations`. MariaDB can't roll back
 * DDL, so every migration must be idempotent (IF [NOT] EXISTS etc.).
 */

function picdropMigrationsDir(): string
{
    return getenv('PICDROP_MIGRATIONS_DIR') ?: dirname(__DIR__, 2) . '/db/migrations';
}

/** @return array<int, string> version => absolute file path, sorted by version */
function picdropPendingMigrations(mysqli $conn): array
{
    $applied = [];
    foreach ($conn->query("SELECT `version` FROM `schema_migrations`") as $row) {
        $applied[(int) $row['version']] = true;
    }

    $pending = [];
    $dir = picdropMigrationsDir();
    foreach (array_merge(glob("$dir/*.sql") ?: [], glob("$dir/*.php") ?: []) as $file) {
        if (!preg_match('/^(\d+)_/', basename($file), $m)) {
            continue;
        }
        $version = (int) $m[1];
        if (isset($pending[$version])) {
            throw new RuntimeException("Duplicate migration version $version");
        }
        if (!isset($applied[$version])) {
            $pending[$version] = $file;
        }
    }
    ksort($pending);
    return $pending;
}

function picdropApplyMigration(mysqli $conn, string $file): void
{
    if (str_ends_with($file, '.php')) {
        $migration = require $file;
        $migration($conn);
        return;
    }

    $conn->multi_query(file_get_contents($file));
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
}

/** @param callable(string): void $log */
function picdropRunMigrations(mysqli $conn, callable $log): void
{
    $lock = $conn->query("SELECT GET_LOCK('picdrop_migrations', 60) AS l")->fetch_assoc();
    if ((int) $lock['l'] !== 1) {
        throw new RuntimeException('Could not acquire migration lock');
    }

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` INT(11) NOT NULL,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $pending = picdropPendingMigrations($conn);
        if (!$pending) {
            $log('Schema is up to date.');
        }
        foreach ($pending as $version => $file) {
            $log('Applying ' . basename($file) . ' ...');
            picdropApplyMigration($conn, $file);

            $stmt = $conn->prepare("INSERT INTO `schema_migrations` (`version`) VALUES (?)");
            $stmt->bind_param("i", $version);
            $stmt->execute();
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('picdrop_migrations')");
    }
}

function picdropCreateBootstrapAdminUser(mysqli $conn, callable $log): void
{
    $username = getenv('ADMIN_USERNAME');
    $password = getenv('ADMIN_PASSWORD');
    $email = getenv('ADMIN_EMAIL');

    if (empty($username) || empty($password) || empty($email)) {
        return;
    }

    $stmt = $conn->prepare("SELECT `id` FROM `users` WHERE `username` = ? OR `email` = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO `users` (`username`, `email`, `password`, `source`, `is_admin`, `is_verified`)
         VALUES (?, ?, ?, 'local', 1, 1)"
    );
    $stmt->bind_param("sss", $username, $email, $hash);
    $stmt->execute();
    $log("Created bootstrap admin user '$username'.");
}
