<?php
require_once __DIR__ . '/view.php';
require_admin();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$stmt = db()->prepare(
    'SELECT u.id, u.name, u.email, u.role, u.max_weekly_minutes, u.created_at,
            COALESCE(SUM((CAST(substr(te.end_time,1,2) AS INTEGER)*60 + CAST(substr(te.end_time,4,2) AS INTEGER)) -
                         (CAST(substr(te.start_time,1,2) AS INTEGER)*60 + CAST(substr(te.start_time,4,2) AS INTEGER)) -
                         te.break_minutes), 0) AS total_minutes
     FROM users u
     LEFT JOIN time_entries te ON te.user_id = u.id AND substr(te.work_date,1,7) = :month
     WHERE u.role IN ("employee", "trainee", "admin") AND u.active = 1
     GROUP BY u.id, u.name, u.email, u.role
     ORDER BY u.name'
);
$stmt->execute(['month' => $month]);
$totals = $stmt->fetchAll();

$monthEnd = $month . '-31';

foreach ($totals as &$row) {
    $userId = (int) $row['id'];
    $weeklyMinutes = (int) ($row['max_weekly_minutes'] ?? 2400);

    $workAllStmt = db()->prepare(
        'SELECT te.work_date, te.start_time, te.end_time, te.break_minutes
         FROM time_entries te
         WHERE te.user_id = :user_id AND te.work_date <= :month_end'
    );
    $workAllStmt->execute(['user_id' => $userId, 'month_end' => $monthEnd]);
    $allEntries = $workAllStmt->fetchAll();

    $totalWorkedMinutes = 0;
    foreach ($allEntries as $entry) {
        $totalWorkedMinutes += max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']);
    }

    $adjAllStmt = db()->prepare(
        'SELECT COALESCE(SUM(minutes_delta), 0) AS adjustment_minutes
         FROM overtime_adjustments
         WHERE user_id = :user_id AND adjustment_date <= :month_end'
    );
    $adjAllStmt->execute(['user_id' => $userId, 'month_end' => $monthEnd]);
    $totalAdjustmentMinutes = (int) ($adjAllStmt->fetch()['adjustment_minutes'] ?? 0);

    $startMonth = new DateTime(substr((string) $row['created_at'], 0, 7) . '-01');
    $endMonth = new DateTime($month . '-01');
    $totalTargetMinutes = 0;
    for ($cursor = clone $startMonth; $cursor <= $endMonth; $cursor->modify('+1 month')) {
        $totalTargetMinutes += (int) round(($weeklyMinutes / 7) * (int) $cursor->format('t'));
    }

    $row['total_overtime_minutes'] = ($totalWorkedMinutes - $totalTargetMinutes) + $totalAdjustmentMinutes;
}
unset($row);


$roleLabels = [
    'employee' => 'Mitarbeiter',
    'trainee' => 'Auszubildender',
    'admin' => 'Admin',
];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gesamtstunden-' . $month . '.csv"');

    echo "Benutzer-ID;Name;E-Mail;Rolle;Monat;Gesamtstunden;Gesamt-Überstunden\n";
    foreach ($totals as $row) {
        $hours = number_format(max(0, ((int) $row['total_minutes']) / 60), 2, ',', '');
        echo (int) $row['id'] . ';"' . str_replace('"', '""', $row['name']) . '";"' . str_replace('"', '""', $row['email']) . '";' . ($roleLabels[$row['role']] ?? $row['role']) . ';' . $month . ';' . $hours . ';' . number_format(((int) $row['total_overtime_minutes']) / 60, 2, ',', '') . "\n";
    }
    exit;
}

if (($_GET['export'] ?? '') === 'user_csv') {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        flash('error', 'Ungültiger Benutzer für CSV Export.');
        header('Location: admin_totals.php?month=' . urlencode($month));
        exit;
    }

    $userStmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = :id AND active = 1');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    if (!$user || !in_array($user['role'], ['employee', 'trainee', 'admin'], true)) {
        flash('error', 'Benutzer nicht gefunden.');
        header('Location: admin_totals.php?month=' . urlencode($month));
        exit;
    }

    $entryStmt = db()->prepare(
        'SELECT te.work_date, te.start_time, te.end_time, te.break_minutes, te.notes, p.name AS project_name, cb.name AS created_by
         FROM time_entries te
         INNER JOIN projects p ON p.id = te.project_id
         INNER JOIN users cb ON cb.id = te.created_by_user_id
         WHERE te.user_id = :user_id AND substr(te.work_date,1,7) = :month
         ORDER BY te.work_date ASC, te.start_time ASC'
    );
    $entryStmt->execute(['user_id' => $userId, 'month' => $month]);
    $entries = $entryStmt->fetchAll();

    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $user['name']);
    $safeName = trim((string) $safeName, '-');
    if ($safeName === '') {
        $safeName = 'benutzer-' . $userId;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stunden-' . $safeName . '-' . $month . '.csv"');

    echo "Benutzer-ID;Name;E-Mail;Rolle;Monat;Datum;Von;Bis;Pause (Minuten);Baustelle;Notiz;Erfasst von;Stunden\n";
    foreach ($entries as $entry) {
        $minutes = max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']);
        $hours = number_format($minutes / 60, 2, ',', '');
        echo (int) $user['id']
            . ';"' . str_replace('"', '""', $user['name']) . '"'
            . ';"' . str_replace('"', '""', $user['email']) . '"'
            . ';' . ($roleLabels[$user['role']] ?? $user['role'])
            . ';' . $month
            . ';' . $entry['work_date']
            . ';' . $entry['start_time']
            . ';' . $entry['end_time']
            . ';' . (int) $entry['break_minutes']
            . ';"' . str_replace('"', '""', $entry['project_name']) . '"'
            . ';"' . str_replace('"', '""', (string) $entry['notes']) . '"'
            . ';"' . str_replace('"', '""', $entry['created_by']) . '"'
            . ';' . $hours . "\n";
    }
    exit;
}

render_header('Admin - Gesamtstunden');
?>
<div class="card">
    <h3>Gesamtstunden pro Benutzer</h3>
    <form method="get" class="grid">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($month) ?>"></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
        <div style="align-self:end"><a class="btn" href="admin_totals.php?month=<?= h($month) ?>&export=csv">CSV Download</a></div>
    </form>

    <table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Monat</th><th>Gesamtstunden</th><th>Gesamt-Überstunden</th><th>Details</th><th>CSV</th></tr>
        <?php foreach ($totals as $row): ?>
            <tr>
                <td><?= (int) $row['id'] ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h($row['email']) ?></td>
                <td><?= h($roleLabels[$row['role']] ?? $row['role']) ?></td>
                <td><?= h($month) ?></td>
                <td><?= h(format_hours(max(0, ((int) $row['total_minutes']) / 60))) ?></td>
                <td><?= h(format_hours(((int) $row['total_overtime_minutes']) / 60)) ?></td>
                <td><a class="btn" href="admin_employee_details.php?user_id=<?= (int) $row['id'] ?>&month=<?= h($month) ?>">Details</a></td>
                <td><a class="btn" href="admin_totals.php?month=<?= h($month) ?>&export=user_csv&user_id=<?= (int) $row['id'] ?>">CSV Benutzer</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
