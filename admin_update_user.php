<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$email = strtolower_safe(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'employee';

if ($id <= 0 || $name === '' || $email === '' || !in_array($role, ['admin', 'employee', 'trainee'], true)) {
    flash('error', 'Ungültige Eingaben beim Benutzer-Update.');
    header('Location: admin_users.php');
    exit;
}

try {
    db()->beginTransaction();

    $stmt = db()->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetch()) {
        db()->rollBack();
        flash('error', 'Benutzer wurde nicht gefunden.');
        header('Location: admin_users.php');
        exit;
    }

    if ($password !== '' && strlen($password) < 8) {
        db()->rollBack();
        flash('error', 'Passwort muss mindestens 8 Zeichen lang sein.');
        header('Location: admin_users.php');
        exit;
    }

    if ($password === '') {
        $updateStmt = db()->prepare('UPDATE users SET name = :name, email = :email, role = :role WHERE id = :id');
        $updateStmt->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
        ]);
    } else {
        $updateStmt = db()->prepare('UPDATE users SET name = :name, email = :email, role = :role, password_hash = :password_hash WHERE id = :id');
        $updateStmt->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    $current = current_user();
    if ($current !== null && $current['id'] === $id && $role !== 'admin') {
        db()->rollBack();
        flash('error', 'Du kannst dir selbst keine Admin-Rechte entziehen.');
        header('Location: admin_users.php');
        exit;
    }

    db()->commit();
    flash('success', 'Benutzer wurde aktualisiert.');
} catch (PDOException $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    flash('error', 'Benutzer konnte nicht aktualisiert werden (E-Mail evtl. bereits vorhanden).');
}

header('Location: admin_users.php');
