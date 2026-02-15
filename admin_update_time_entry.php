<?php
require_once __DIR__ . '/init.php';
require_admin();
verify_csrf();

$entryId = (int) ($_POST['entry_id'] ?? 0);
$targetUserId = (int) ($_POST['user_id'] ?? 0);
$workDate = $_POST['work_date'] ?? '';
$start = $_POST['start_time'] ?? '';
$end = $_POST['end_time'] ?? '';
$break = max(0, (int) ($_POST['break_minutes'] ?? 0));
$projectId = (int) ($_POST['project_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($entryId <= 0 || $targetUserId <= 0) {
    flash('error', 'Ungültiger Eintrag.');
    header('Location: admin_time_entry.php');
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !is_quarter_time($start) || !is_quarter_time($end)) {
    flash('error', 'Ungültige Datums- oder Zeitangabe (Zeiten nur 00, 15, 30, 45).');
    header('Location: admin_time_entry.php?entry_id=' . $entryId);
    exit;
}

$duration = minutes_between($start, $end);
if ($duration <= 0 || $break >= $duration) {
    flash('error', 'Bitte gültige Von/Bis-Zeit und Pause eintragen.');
    header('Location: admin_time_entry.php?entry_id=' . $entryId);
    exit;
}

$projectStmt = db()->prepare('SELECT COUNT(*) AS c FROM user_projects WHERE user_id = :user_id AND project_id = :project_id');
$projectStmt->execute(['user_id' => $targetUserId, 'project_id' => $projectId]);
if ((int) $projectStmt->fetch()['c'] === 0) {
    flash('error', 'Benutzer ist dieser Baustelle nicht zugewiesen.');
    header('Location: admin_time_entry.php?entry_id=' . $entryId);
    exit;
}

$overlapStmt = db()->prepare(
    'SELECT COUNT(*) AS c FROM time_entries
     WHERE user_id = :user_id AND work_date = :work_date AND id != :id
     AND NOT (end_time <= :start_time OR start_time >= :end_time)'
);
$overlapStmt->execute([
    'user_id' => $targetUserId,
    'work_date' => $workDate,
    'id' => $entryId,
    'start_time' => $start,
    'end_time' => $end,
]);
if ((int) $overlapStmt->fetch()['c'] > 0) {
    flash('error', 'Der Zeitraum überschneidet sich mit einem vorhandenen Eintrag.');
    header('Location: admin_time_entry.php?entry_id=' . $entryId);
    exit;
}

$upd = db()->prepare(
    'UPDATE time_entries
     SET user_id = :user_id,
         project_id = :project_id,
         work_date = :work_date,
         start_time = :start_time,
         end_time = :end_time,
         break_minutes = :break_minutes,
         notes = :notes
     WHERE id = :id'
);
$upd->execute([
    'id' => $entryId,
    'user_id' => $targetUserId,
    'project_id' => $projectId,
    'work_date' => $workDate,
    'start_time' => $start,
    'end_time' => $end,
    'break_minutes' => $break,
    'notes' => $notes,
]);

flash('success', 'Stundeneintrag wurde aktualisiert.');
header('Location: admin_time_entry.php');
