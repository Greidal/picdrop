-- Columns added after the initial release; no-ops on fresh installs.
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `logo_path` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `setting_show_bar` TINYINT(1) DEFAULT 1;
