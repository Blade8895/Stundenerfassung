<?php
require_once __DIR__ . '/init.php';
require_login();

flash('success', 'Monatsauswertung wurde entfernt. Bitte Dashboard verwenden.');
header('Location: dashboard.php');
