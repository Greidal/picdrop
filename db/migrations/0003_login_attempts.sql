-- Failed logins, used to throttle brute-force attempts per account.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Speeds up gallery/slideshow queries (filter by event, sort by time).
CREATE INDEX IF NOT EXISTS `idx_uploads_event_time` ON `uploads` (`event_id`, `timestamp`);
