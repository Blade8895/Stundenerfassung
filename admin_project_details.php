<?php
require_once __DIR__ . '/view.php';
require_admin();

$projectId = (int) ($_GET['project_id'] ?? 0);
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$backParams = [
    'name' => trim($_GET['name'] ?? ''),
    'created_from' => trim($_GET['created_from'] ?? ''),
    'created_to' => trim($_GET['created_to'] ?? ''),
    'sort' => trim($_GET['sort'] ?? ''),
    'user_id' => trim($_GET['user_id'] ?? ''),
];
$backQuery = http_build_query(array_filter($backParams, static fn($value) => $value !== ''));
$backUrl = 'admin_projects.php' . ($backQuery !== '' ? '?' . $backQuery : '');

if ($projectId <= 0) {
    flash('error', 'Ungültige Baustelle.');
    header('Location: ' . $backUrl);
    exit;
}

$projectStmt = db()->prepare('SELECT id, name, created_at FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$project = $projectStmt->fetch();
if (!$project) {
    flash('error', 'Baustelle nicht gefunden.');
    header('Location: ' . $backUrl);
    exit;
}

$entryStmt = db()->prepare(
    'SELECT te.work_date, te.start_time, te.end_time, te.break_minutes, te.notes,
            u.name AS user_name,
            cb.name AS created_by_name
     FROM time_entries te
     INNER JOIN users u ON u.id = te.user_id
     LEFT JOIN users cb ON cb.id = te.created_by_user_id
     WHERE te.project_id = :project_id
       AND substr(te.work_date, 1, 7) = :month
     ORDER BY te.work_date DESC, te.start_time DESC'
);
$entryStmt->execute(['project_id' => $projectId, 'month' => $month]);
$entries = $entryStmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    $safeProject = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $project['name']);
    $safeProject = trim((string) $safeProject, '-');
    if ($safeProject === '') {
        $safeProject = 'baustelle-' . $projectId;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="baustellenstunden-' . $safeProject . '-' . $month . '.csv"');

    echo "Baustellen-ID;Baustelle;Monat;Datum;Benutzer;Von;Bis;Pause (Minuten);Notiz;Erfasst von;Stunden\n";
    foreach ($entries as $entry) {
        $minutes = max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']);
        $hours = number_format($minutes / 60, 2, ',', '');

        echo (int) $project['id']
            . ';"' . str_replace('"', '""', $project['name']) . '"'
            . ';' . $month
            . ';' . $entry['work_date']
            . ';"' . str_replace('"', '""', $entry['user_name']) . '"'
            . ';' . $entry['start_time']
            . ';' . $entry['end_time']
            . ';' . (int) $entry['break_minutes']
            . ';"' . str_replace('"', '""', (string) $entry['notes']) . '"'
            . ';"' . str_replace('"', '""', (string) ($entry['created_by_name'] ?? '-')) . '"'
            . ';' . $hours . "\n";
    }
    exit;
}

$totalMinutes = 0;
foreach ($entries as $entry) {
    $totalMinutes += max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']);
}

render_header('Admin - Baustellendetails');
?>
<div class="card">
    <h3>Baustellendetails</h3>
    <p><strong>Baustelle:</strong> <?= h($project['name']) ?></p>
    <p><strong>ID:</strong> <?= (int) $project['id'] ?></p>
    <p><strong>Angelegt:</strong> <?= h($project['created_at']) ?></p>

    <form method="get" class="grid">
        <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">
        <input type="hidden" name="name" value="<?= h($backParams['name']) ?>">
        <input type="hidden" name="created_from" value="<?= h($backParams['created_from']) ?>">
        <input type="hidden" name="created_to" value="<?= h($backParams['created_to']) ?>">
        <input type="hidden" name="sort" value="<?= h($backParams['sort']) ?>">
        <input type="hidden" name="user_id" value="<?= h($backParams['user_id']) ?>">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($month) ?>"></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
        <div style="align-self:end"><a class="btn" href="admin_project_details.php?project_id=<?= (int) $projectId ?>&month=<?= h($month) ?>&name=<?= urlencode($backParams['name']) ?>&created_from=<?= urlencode($backParams['created_from']) ?>&created_to=<?= urlencode($backParams['created_to']) ?>&sort=<?= urlencode($backParams['sort']) ?>&user_id=<?= urlencode($backParams['user_id']) ?>&export=csv">CSV Download</a></div>
        <div style="align-self:end"><a class="btn" href="<?= h($backUrl) ?>">Zurück zu Baustellen</a></div>
    </form>

    <p><strong>Gesamtstunden im Monat:</strong> <?= h(format_hours($totalMinutes / 60)) ?></p>
</div>

<div class="card">
    <h3>Einzelne Einträge</h3>
    <table>
        <tr><th>Datum</th><th>Benutzer</th><th>Von</th><th>Bis</th><th>Pause</th><th>Notiz</th><th>Erfasst von</th><th>Stunden</th></tr>
        <?php foreach ($entries as $entry): ?>
            <?php $minutes = max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']); ?>
            <tr>
                <td><?= h($entry['work_date']) ?></td>
                <td><?= h($entry['user_name']) ?></td>
                <td><?= h($entry['start_time']) ?></td>
                <td><?= h($entry['end_time']) ?></td>
                <td><?= (int) $entry['break_minutes'] ?> Min.</td>
                <td><?= h((string) $entry['notes']) ?></td>
                <td><?= h($entry['created_by_name'] ?? '-') ?></td>
                <td><?= h(format_hours($minutes / 60)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
