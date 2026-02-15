<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$name = trim($_POST['name'] ?? '');
if ($name === '') {
    flash('error', 'Baustellenname darf nicht leer sein.');
    header('Location: admin.php');
    exit;
}

try {
    $stmt = db()->prepare('INSERT INTO projects(name, active, created_at) VALUES(:name, 1, :created_at)');
    $stmt->execute(['name' => $name, 'created_at' => now()]);
    flash('success', 'Baustelle angelegt.');
} catch (PDOException $e) {
    flash('error', 'Baustelle existiert bereits.');
}

header('Location: admin.php');
