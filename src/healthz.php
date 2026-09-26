<?php
// Container health check: web server, PHP and database are up.
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain');
header('Cache-Control: no-store');
$conn->query('SELECT 1');
echo 'ok';
