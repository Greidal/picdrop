-- Dashboard: who created an event, archiving. Settings: event location, BAC overlay, capture time.
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `created_by` INT(11) DEFAULT NULL;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `archived_at` DATETIME DEFAULT NULL;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `location` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `setting_show_location` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `setting_show_bac` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `setting_use_capture_time` TINYINT(1) NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS `idx_events_created_by` ON `events` (`created_by`);

-- Existing events: the first user with access is the one who created it (admin.php inserts that row first).
UPDATE `events` e SET `created_by` = (
  SELECT eu.`user_id` FROM `event_users` eu WHERE eu.`event_uuid` = e.`uuid` ORDER BY eu.`id` LIMIT 1
) WHERE e.`created_by` IS NULL;

-- Drink menu: default amount and alcohol content; the picture becomes optional.
ALTER TABLE `drinks` ADD COLUMN IF NOT EXISTS `volume_ml` INT(11) NOT NULL DEFAULT 500;
ALTER TABLE `drinks` ADD COLUMN IF NOT EXISTS `abv` DECIMAL(4,1) NOT NULL DEFAULT 5.0;
ALTER TABLE `drinks` MODIFY `image_path` VARCHAR(255) NULL;

-- Teams guests can join to compete against each other.
CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `name` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_teams_event_name` (`event_uuid`, `name`),
  CONSTRAINT `fk_teams_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Per-guest (device) data for the BAC estimate.
CREATE TABLE IF NOT EXISTS `drinker_profiles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `device_uuid` VARCHAR(64) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `weight_kg` DECIMAL(5,1) NOT NULL DEFAULT 75.0,
  `sex` ENUM('m','f') NOT NULL DEFAULT 'm',
  `team_id` INT(11) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_profiles_device` (`event_uuid`, `device_uuid`),
  CONSTRAINT `fk_profiles_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_profiles_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per drink and person. Drinks can be checked in without a photo and for others
-- (rounds), so they no longer live in `uploads`; uploads.drink_id stays for the slideshow caption.
CREATE TABLE IF NOT EXISTS `checkins` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_uuid` VARCHAR(36) NOT NULL,
  `device_uuid` VARCHAR(64) DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `drink_id` INT(11) NOT NULL,
  `volume_ml` INT(11) NOT NULL,
  `abv` DECIMAL(4,1) NOT NULL,
  `upload_id` INT(11) DEFAULT NULL,
  `round_by` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checkins_event_device` (`event_uuid`, `device_uuid`, `created_at`),
  CONSTRAINT `fk_checkins_event` FOREIGN KEY (`event_uuid`) REFERENCES `events` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_checkins_drink` FOREIGN KEY (`drink_id`) REFERENCES `drinks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_checkins_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Earlier drink check-ins (photo + drink) keep counting.
INSERT INTO `checkins` (`event_uuid`, `device_uuid`, `name`, `drink_id`, `volume_ml`, `abv`, `upload_id`, `created_at`)
SELECT u.`event_id`, u.`device_uuid`, COALESCE(u.`uploader_name`, ''), u.`drink_id`, d.`volume_ml`, d.`abv`, u.`id`, u.`timestamp`
FROM `uploads` u JOIN `drinks` d ON d.`id` = u.`drink_id`
WHERE NOT EXISTS (SELECT 1 FROM `checkins` c WHERE c.`upload_id` = u.`id`);
