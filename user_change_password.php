<?php
require_once __DIR__ . '/init.php';
require_login();
verify_csrf();

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (strlen($newPassword) < 8) {
    flash('error', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.');
    header('Location: user_settings.php');
    exit;
}

if ($newPassword !== $confirmPassword) {
    flash('error', 'Die neuen Passwörter stimmen nicht überein.');
    header('Location: user_settings.php');
    exit;
}

$user = current_user();
if ($user === null || !password_verify($currentPassword, $user['password_hash'])) {
    flash('error', 'Das aktuelle Passwort ist falsch.');
    header('Location: user_settings.php');
    exit;
}

$stmt = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => $user['id'],
]);

flash('success', 'Dein Passwort wurde geändert.');
header('Location: user_settings.php');
