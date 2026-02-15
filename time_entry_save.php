<?php
require_once __DIR__ . '/init.php';
require_login();
verify_csrf();

$user = current_user();
$isAdmin = is_admin();

$targetUserId = (int) ($_POST['user_id'] ?? $user['id']);
$workDate = $_POST['work_date'] ?? '';
$start = $_POST['start_time'] ?? '';
$end = $_POST['end_time'] ?? '';
$break = max(0, (int) ($_POST['break_minutes'] ?? 0));
$projectId = (int) ($_POST['project_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

function is_quarter_time(string $time): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    [$h, $m] = array_map('intval', explode(':', $time));
    if ($h < 0 || $h > 23) {
        return false;
    }

    return in_array($m, [0, 15, 30, 45], true);
}


if (!$isAdmin) {
    $targetUserId = (int) $user['id'];
    $workDate = date('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !is_quarter_time($start) || !is_quarter_time($end)) {
    flash('error', 'Ungültige Datums- oder Zeitangabe (Zeiten nur 00, 15, 30, 45).');
    header('Location: dashboard.php');
    exit;
}

$duration = minutes_between($start, $end);
if ($duration <= 0 || $break >= $duration) {
    flash('error', 'Bitte gültige Von/Bis-Zeit und Pause eintragen.');
    header('Location: ' . ($isAdmin ? 'admin.php' : 'dashboard.php'));
    exit;
}

$projectStmt = db()->prepare('SELECT COUNT(*) AS c FROM user_projects WHERE user_id = :user_id AND project_id = :project_id');
$projectStmt->execute(['user_id' => $targetUserId, 'project_id' => $projectId]);
if ((int) $projectStmt->fetch()['c'] === 0) {
    flash('error', 'Mitarbeiter ist dieser Baustelle nicht zugewiesen.');
    header('Location: ' . ($isAdmin ? 'admin.php' : 'dashboard.php'));
    exit;
}

$overlapStmt = db()->prepare(
    'SELECT COUNT(*) AS c FROM time_entries
     WHERE user_id = :user_id AND work_date = :work_date
     AND NOT (end_time <= :start_time OR start_time >= :end_time)'
);
$overlapStmt->execute([
    'user_id' => $targetUserId,
    'work_date' => $workDate,
    'start_time' => $start,
    'end_time' => $end,
]);
if ((int) $overlapStmt->fetch()['c'] > 0) {
    flash('error', 'Der Zeitraum überschneidet sich mit einem vorhandenen Eintrag.');
    header('Location: ' . ($isAdmin ? 'admin.php' : 'dashboard.php'));
    exit;
}

$stmt = db()->prepare(
    'INSERT INTO time_entries(user_id, project_id, work_date, start_time, end_time, break_minutes, notes, created_by_user_id, created_at)
     VALUES(:user_id, :project_id, :work_date, :start_time, :end_time, :break_minutes, :notes, :created_by_user_id, :created_at)'
);
$stmt->execute([
    'user_id' => $targetUserId,
    'project_id' => $projectId,
    'work_date' => $workDate,
    'start_time' => $start,
    'end_time' => $end,
    'break_minutes' => $break,
    'notes' => $notes,
    'created_by_user_id' => $user['id'],
    'created_at' => now(),
]);

flash('success', 'Stunden wurden gespeichert.');
header('Location: ' . ($isAdmin ? 'admin.php' : 'dashboard.php'));
