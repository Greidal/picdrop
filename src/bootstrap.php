<?php

function picdropSchemaStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `source` VARCHAR(20) DEFAULT 'local',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_admin` TINYINT(1) DEFAULT 0,
  `verify_token` VARCHAR(100) DEFAULT NULL,
  `is_verified` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_username` (`username`),
  UNIQUE KEY `idx_unique_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `events` (
  `uuid` VARCHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `setting_show_badge` TINYINT(1) DEFAULT 1,
  `setting_show_uploader` TINYINT(1) DEFAULT 1,
  `setting_show_time` TINYINT(1) DEFAULT 1,
  `setting_show_event_name` TINYINT(1) DEFAULT 1,
  `setting_slide_duration` INT(11) DEFAULT 8000,
  `setting_merge_by_device` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `drinks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `score_factor` DECIMAL(4,1) DEFAULT 1.0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_drinks_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `event_invites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_event_email` (`event_uuid`, `email`),
  CONSTRAINT `fk_invites_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `event_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `user_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_access` (`event_uuid`, `user_id`),
  CONSTRAINT `fk_event_users_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `uploads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(36) NOT NULL,
  `device_uuid` VARCHAR(64) DEFAULT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `uploader_name` VARCHAR(100) DEFAULT NULL,
  `drink_id` INT(11) DEFAULT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_device_uuid` (`device_uuid`),
  CONSTRAINT `fk_uploads_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_uploads_drink` FOREIGN KEY (`drink_id`) REFERENCES `drinks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `blocked_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `device_uuid` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_block_unique` (`event_uuid`, `device_uuid`),
  CONSTRAINT `fk_blocked_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `live_reactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `emoji` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_poll` (`event_uuid`, `id`),
  CONSTRAINT `fk_reactions_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
}

function picdropDatabaseSchemaVersionExists(mysqli $conn, int $version): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM `schema_migrations` WHERE `version` = ? LIMIT 1");
    $stmt->bind_param("i", $version);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

function picdropEnsureSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` INT(11) NOT NULL,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (picdropDatabaseSchemaVersionExists($conn, 1)) {
        return;
    }

    foreach (picdropSchemaStatements() as $sql) {
        $conn->query($sql);
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO `schema_migrations` (`version`) VALUES (1)");
    $stmt->execute();
}
