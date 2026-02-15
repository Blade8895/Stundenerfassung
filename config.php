<?php
return [
    'app_name' => 'Stundenerfassung',
    'timezone' => 'Europe/Berlin',
    // Beispiel für MySQL: mysql:host=localhost;dbname=stundenerfassung;charset=utf8mb4
    // Standardmäßig SQLite für einfache Bereitstellung auf Webspace.
    'dsn' => 'sqlite:' . __DIR__ . '/data/stundenerfassung.sqlite',
    'db_user' => null,
    'db_pass' => null,
    'session_name' => 'stundenerfassung_session',
];
