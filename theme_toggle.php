<?php
require_once __DIR__ . '/init.php';
require_login();

$theme = $_GET['theme'] ?? 'light';
set_theme($theme);

$redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header('Location: ' . $redirect);
