<?php
// SMTP Configuration from environment variables
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.ionos.de');
define('SMTP_USER', getenv('SMTP_USER') ?: 'vertrieb@klimarschanlage.de');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@klimarschanlage.de');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'PicDrop Fotobox');
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: 'AkkuflexWeizenVersammlung');
