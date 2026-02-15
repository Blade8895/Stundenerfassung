<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$projectId = (int) ($_POST['project_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
if ($name === '') {
    flash('error', 'Baustellenname darf nicht leer sein.');
    header('Location: admin_projects.php');
    exit;
}

try {
    if ($projectId > 0) {
        $stmt = db()->prepare('UPDATE projects SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $projectId]);
        if ($stmt->rowCount() === 0) {
            flash('error', 'Baustelle mit dieser ID nicht gefunden.');
        } else {
            flash('success', 'Baustelle aktualisiert.');
        }
    } else {
        $stmt = db()->prepare('INSERT INTO projects(name, active, created_at) VALUES(:name, 1, :created_at)');
        $stmt->execute(['name' => $name, 'created_at' => now()]);
        flash('success', 'Baustelle angelegt.');
    }
} catch (PDOException $e) {
    flash('error', 'Baustelle konnte nicht gespeichert werden (Name evtl. bereits vorhanden).');
}

header('Location: admin_projects.php');
