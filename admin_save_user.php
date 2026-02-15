<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$name = trim($_POST['name'] ?? '');
$email = mb_strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'employee';

if ($name === '' || $email === '' || strlen($password) < 8 || !in_array($role, ['admin', 'employee'], true)) {
    flash('error', 'Ungültige Eingaben beim Benutzer.');
    header('Location: admin.php');
    exit;
}

try {
    $stmt = db()->prepare('INSERT INTO users(name, email, password_hash, role, active, created_at) VALUES(:name, :email, :password_hash, :role, 1, :created_at)');
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'created_at' => now(),
    ]);
    flash('success', 'Benutzer wurde erstellt.');
} catch (PDOException $e) {
    flash('error', 'Benutzer konnte nicht erstellt werden (E-Mail evtl. bereits vorhanden).');
}

header('Location: admin.php');
