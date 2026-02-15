<?php
require_once __DIR__ . '/view.php';
require_login();

$user = current_user();
$month = $_GET['month'] ?? date('Y-m');

$stmt = db()->prepare(
    'SELECT te.work_date, te.start_time, te.end_time, te.break_minutes, p.name AS project_name
     FROM time_entries te
     INNER JOIN projects p ON p.id = te.project_id
     WHERE te.user_id = :user_id AND substr(te.work_date,1,7) = :month
     ORDER BY te.work_date, p.name'
);
$stmt->execute(['user_id' => $user['id'], 'month' => $month]);
$rawRows = $stmt->fetchAll();

$grouped = [];
$totalMinutes = 0;
foreach ($rawRows as $row) {
    $minutes = max(0, minutes_between($row['start_time'], $row['end_time']) - (int) $row['break_minutes']);
    $key = $row['work_date'] . '|' . $row['project_name'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'work_date' => $row['work_date'],
            'project_name' => $row['project_name'],
            'minutes' => 0,
        ];
    }
    $grouped[$key]['minutes'] += $minutes;
    $totalMinutes += $minutes;
}

render_header('Monatsauswertung');
?>
<div class="card">
    <h3>Monatsauswertung</h3>
    <form method="get" class="grid">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($month) ?>"></div>
        <div style="align-self:end"><button type="submit">Aktualisieren</button></div>
    </form>
    <p><strong>Gesamtstunden:</strong> <?= h(format_hours($totalMinutes / 60)) ?></p>
    <table>
        <tr><th>Datum</th><th>Baustelle</th><th>Stunden</th></tr>
        <?php foreach ($grouped as $row): ?>
            <tr>
                <td><?= h($row['work_date']) ?></td>
                <td><?= h($row['project_name']) ?></td>
                <td><?= h(format_hours($row['minutes'] / 60)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
