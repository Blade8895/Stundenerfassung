<?php
require_once __DIR__ . '/view.php';
require_admin();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$stmt = db()->prepare(
    'SELECT u.id, u.name, u.email,
            COALESCE(SUM((CAST(substr(te.end_time,1,2) AS INTEGER)*60 + CAST(substr(te.end_time,4,2) AS INTEGER)) -
                         (CAST(substr(te.start_time,1,2) AS INTEGER)*60 + CAST(substr(te.start_time,4,2) AS INTEGER)) -
                         te.break_minutes), 0) AS total_minutes
     FROM users u
     LEFT JOIN time_entries te ON te.user_id = u.id AND substr(te.work_date,1,7) = :month
     WHERE u.role = "employee" AND u.active = 1
     GROUP BY u.id, u.name, u.email
     ORDER BY u.name'
);
$stmt->execute(['month' => $month]);
$totals = $stmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gesamtstunden-' . $month . '.csv"');

    echo "Mitarbeiter-ID;Name;E-Mail;Monat;Gesamtstunden\n";
    foreach ($totals as $row) {
        $hours = number_format(max(0, ((int) $row['total_minutes']) / 60), 2, ',', '');
        echo (int) $row['id'] . ';"' . str_replace('"', '""', $row['name']) . '";"' . str_replace('"', '""', $row['email']) . '";' . $month . ';' . $hours . "\n";
    }
    exit;
}

render_header('Admin - Gesamtstunden');
?>
<div class="card">
    <h3>Gesamtstunden pro Mitarbeiter</h3>
    <form method="get" class="grid">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($month) ?>"></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
        <div style="align-self:end"><a class="btn" href="admin_totals.php?month=<?= h($month) ?>&export=csv">CSV Download</a></div>
    </form>

    <table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Monat</th><th>Gesamtstunden</th></tr>
        <?php foreach ($totals as $row): ?>
            <tr>
                <td><?= (int) $row['id'] ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h($row['email']) ?></td>
                <td><?= h($month) ?></td>
                <td><?= h(format_hours(max(0, ((int) $row['total_minutes']) / 60))) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
