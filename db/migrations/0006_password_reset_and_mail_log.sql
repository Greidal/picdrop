-- Password reset tokens (only the SHA-256 hash of the token is stored).
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_password_resets_token` (`token_hash`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Account-related mails sent per address, used to throttle abuse of the mail forms.
CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `purpose` VARCHAR(30) NOT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_log_email` (`email`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verification links expire; existing open links stay valid for another 7 days.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `verify_expires_at` DATETIME DEFAULT NULL;
UPDATE `users` SET `verify_expires_at` = NOW() + INTERVAL 7 DAY
  WHERE `verify_token` IS NOT NULL AND `verify_expires_at` IS NULL;
