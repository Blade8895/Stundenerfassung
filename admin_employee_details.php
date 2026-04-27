<?php
require_once __DIR__ . '/view.php';
require_admin();

$userId = (int) ($_GET['user_id'] ?? 0);
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

if ($userId <= 0) {
    flash('error', 'Ungültiger Benutzer.');
    header('Location: admin_totals.php?month=' . urlencode($month));
    exit;
}

$userStmt = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = :id');
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
     ORDER BY te.work_date DESC, te.start_time DESC'
);
$entryStmt->execute(['user_id' => $userId, 'month' => $month]);
$entries = $entryStmt->fetchAll();

$totalMinutes = 0;
$entryDates = [];
foreach ($entries as $entry) {
    $totalMinutes += max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']);
    $entryDates[$entry['work_date']] = true;
}

$monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
if (!$monthStart) {
    $monthStart = new DateTimeImmutable(date('Y-m-01'));
}
$monthEnd = $monthStart->modify('last day of this month');
$calendarStart = $monthStart->modify('-' . ((int) $monthStart->format('N') - 1) . ' days');
$calendarEnd = $monthEnd->modify('+' . (7 - (int) $monthEnd->format('N')) . ' days');
$today = new DateTimeImmutable('today');

render_header('Admin - Benutzerdetails');
?>
<div class="card">
    <h3>Benutzerdetails</h3>
    <p><strong>Name:</strong> <?= h($user['name']) ?> (<?= h($user['email']) ?>)</p>
    <form method="get" class="grid">
        <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($month) ?>"></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
        <div style="align-self:end"><a class="btn" href="admin_totals.php?month=<?= h($month) ?>">Zurück zu Gesamtstunden</a></div>
    </form>
    <p><strong>Gesamtstunden im Monat:</strong> <?= h(format_hours($totalMinutes / 60)) ?></p>
</div>

<div class="card">
    <h3>Kalenderübersicht</h3>
    <div class="calendar-grid">
        <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday): ?>
            <div class="calendar-weekday"><?= h($weekday) ?></div>
        <?php endforeach; ?>

        <?php for ($day = $calendarStart; $day <= $calendarEnd; $day = $day->modify('+1 day')): ?>
            <?php
            $dayIso = $day->format('Y-m-d');
            $isCurrentMonth = $day->format('Y-m') === $month;
            $hasEntry = isset($entryDates[$dayIso]);
            $isWeekend = in_array((int) $day->format('N'), [6, 7], true);
            $isPast = $day < $today;
            $dayClass = ['calendar-day'];

            if (!$isCurrentMonth) {
                $dayClass[] = 'calendar-day--outside';
            }

            if ($hasEntry) {
                $dayClass[] = 'calendar-day--has-entry';
            } elseif ($isCurrentMonth && $isPast && !$isWeekend) {
                $dayClass[] = 'calendar-day--missing-entry';
            }
            ?>
            <div class="<?= h(implode(' ', $dayClass)) ?>">
                <span class="calendar-day-number"><?= h($day->format('j')) ?></span>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="card">
    <h3>Einzelne Einträge (Baustelle + Notiz)</h3>
    <table>
        <tr><th>Datum</th><th>Von</th><th>Bis</th><th>Pause</th><th>Baustelle</th><th>Notiz</th><th>Erfasst von</th><th>Stunden</th></tr>
        <?php foreach ($entries as $entry): ?>
            <?php $minutes = max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']); ?>
            <tr>
                <td><?= h($entry['work_date']) ?></td>
                <td><?= h($entry['start_time']) ?></td>
                <td><?= h($entry['end_time']) ?></td>
                <td><?= (int) $entry['break_minutes'] ?> Min.</td>
                <td><?= h($entry['project_name']) ?></td>
                <td><?= h((string) $entry['notes']) ?></td>
                <td><?= h($entry['created_by']) ?></td>
                <td><?= h(format_hours($minutes / 60)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
