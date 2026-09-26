<?php
require_once __DIR__ . '/lib/auth.php';
logoutUser();
header("Location: login.php");
