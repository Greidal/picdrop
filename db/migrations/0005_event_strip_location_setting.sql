-- Opt-in per event: remove GPS/location metadata from uploaded photos.
ALTER TABLE `events` ADD COLUMN IF NOT EXISTS `setting_strip_location` TINYINT(1) NOT NULL DEFAULT 0;
