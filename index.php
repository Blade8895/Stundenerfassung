<?php
require_once __DIR__ . '/init.php';
if (current_user()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
