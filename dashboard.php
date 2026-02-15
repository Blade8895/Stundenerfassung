<?php
require_once __DIR__ . '/view.php';
require_login();

$user = current_user();
$selectedMonth = $_GET['month'] ?? date('Y-m');

$stmt = db()->prepare(
    'SELECT p.id, p.name FROM projects p
     INNER JOIN user_projects up ON up.project_id = p.id
     WHERE up.user_id = :user_id AND p.active = 1
     ORDER BY p.name'
);
$stmt->execute(['user_id' => $user['id']]);
$projects = $stmt->fetchAll();

$listStmt = db()->prepare(
    'SELECT te.*, p.name AS project_name
     FROM time_entries te
     INNER JOIN projects p ON p.id = te.project_id
     WHERE te.user_id = :user_id AND substr(te.work_date,1,7) = :month
     ORDER BY te.work_date DESC, te.start_time DESC'
);
$listStmt->execute(['user_id' => $user['id'], 'month' => $selectedMonth]);
$entries = $listStmt->fetchAll();

$totalMinutes = 0;
foreach ($entries as $entry) {
    $minutes = minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes'];
    $totalMinutes += max(0, $minutes);
}

render_header('Dashboard');
?>
<div class="card">
    <h3>Zeiten eintragen</h3>
    <?php if (count($projects) === 0): ?>
        <p>Dir wurde noch keine Baustelle zugewiesen. Bitte Admin kontaktieren.</p>
    <?php else: ?>
    <form method="post" action="time_entry_save.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <input type="hidden" name="work_date" value="<?= h(date('Y-m-d')) ?>">
            <div><label>Arbeitstag</label><input value="<?= h(date('d.m.Y')) ?>" readonly></div>
            <div><label>Von</label><input type="time" name="start_time" step="900" required></div>
            <div><label>Bis</label><input type="time" name="end_time" step="900" required></div>
            <div><label>Pause (Min.)</label><input type="number" name="break_minutes" min="0" value="0" required></div>
            <div><label>Baustelle</label><select name="project_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"><?= h($project['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Notiz</label><input name="notes" maxlength="200"></div>
        </div>
        <p><button type="submit">Speichern</button></p>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Meine Zeiten</h3>
    <form method="get" class="grid">
        <div><label>Monat</label><input type="month" name="month" value="<?= h($selectedMonth) ?>"></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
    </form>
    <p><strong>Gesamtstunden:</strong> <?= h(format_hours($totalMinutes / 60)) ?></p>
    <div class="table-wrap"><table>
        <tr><th>Datum</th><th>Von</th><th>Bis</th><th>Pause</th><th>Baustelle</th><th>Notiz</th><th>Stunden</th></tr>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?= h($entry['work_date']) ?></td>
            <td><?= h($entry['start_time']) ?></td>
            <td><?= h($entry['end_time']) ?></td>
            <td><?= (int) $entry['break_minutes'] ?> Min.</td>
            <td><?= h($entry['project_name']) ?></td>
            <td><?= h((string)$entry['notes']) ?></td>
            <td><?php $entryMinutes = max(0, minutes_between($entry['start_time'], $entry['end_time']) - (int) $entry['break_minutes']); echo h(format_hours($entryMinutes / 60)); ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php render_footer(); ?>
