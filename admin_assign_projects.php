<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$userId = (int) ($_POST['user_id'] ?? 0);
$projectIds = array_map('intval', $_POST['project_ids'] ?? []);

if ($userId <= 0) {
    flash('error', 'Ungültiger Benutzer.');
    header('Location: admin.php');
    exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM user_projects WHERE user_id = :user_id');
    $del->execute(['user_id' => $userId]);

    $ins = $pdo->prepare('INSERT INTO user_projects(user_id, project_id) VALUES(:user_id, :project_id)');
    foreach ($projectIds as $projectId) {
        if ($projectId > 0) {
            $ins->execute(['user_id' => $userId, 'project_id' => $projectId]);
        }
    }

    $pdo->commit();
    flash('success', 'Baustellen-Zuweisung gespeichert.');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('error', 'Fehler beim Speichern der Zuweisung.');
}

header('Location: admin.php?user_id=' . $userId);
