<?php
// All configuration comes from environment variables (see example.env).

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.ionos.de');
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'PicDrop Fotobox');

// Empty = open registration is disabled; only invited users can sign up.
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: '');

// Public base URL, e.g. https://picdrop.example.com. Used for links in e-mails
// so they can't be manipulated via the Host header.
define('APP_URL', rtrim(getenv('APP_URL') ?: '', '/'));

define('PAGE_TITLE', getenv('PAGE_TITLE') ?: 'Kamerarsch');

define('INVITE_TTL_DAYS', 14);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 15);
define('PASSWORD_MIN_LENGTH', 10);
