<?php
require_once __DIR__ . '/view.php';
require_admin();

$users = db()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY role DESC, name')->fetchAll();
$projects = db()->query('SELECT id, name FROM projects WHERE active = 1 ORDER BY name')->fetchAll();
$hours = range(0, 23);
$quarterMinutes = ['00', '15', '30', '45'];

$editEntryId = (int) ($_GET['entry_id'] ?? 0);
$filterUserId = (int) ($_GET['filter_user_id'] ?? 0);
$filterMonth = $_GET['filter_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = date('Y-m');
}

$entryForEdit = null;
if ($editEntryId > 0) {
    $editStmt = db()->prepare('SELECT * FROM time_entries WHERE id = :id');
    $editStmt->execute(['id' => $editEntryId]);
    $entryForEdit = $editStmt->fetch() ?: null;
}

$formData = [
    'user_id' => $entryForEdit['user_id'] ?? '',
    'work_date' => $entryForEdit['work_date'] ?? date('Y-m-d'),
    'start_time' => $entryForEdit['start_time'] ?? '08:00',
    'end_time' => $entryForEdit['end_time'] ?? '16:00',
    'break_minutes' => $entryForEdit['break_minutes'] ?? 0,
    'project_id' => $entryForEdit['project_id'] ?? '',
    'notes' => $entryForEdit['notes'] ?? '',
];

[$startHour, $startMinute] = explode(':', $formData['start_time']);
[$endHour, $endMinute] = explode(':', $formData['end_time']);

$where = ['substr(te.work_date,1,7) = :month'];
$params = ['month' => $filterMonth];
if ($filterUserId > 0) {
    $where[] = 'te.user_id = :user_id';
    $params['user_id'] = $filterUserId;
}

$listSql = 'SELECT te.id, te.user_id, te.work_date, te.start_time, te.end_time, te.break_minutes, te.notes,
                   u.name AS user_name, p.name AS project_name
            FROM time_entries te
            INNER JOIN users u ON u.id = te.user_id
            INNER JOIN projects p ON p.id = te.project_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY te.work_date DESC, te.start_time DESC
            LIMIT 200';
$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$entries = $listStmt->fetchAll();

render_header('Admin - Stunden nachtragen');
?>
<div class="card">
    <h3><?= $entryForEdit ? 'Stundeneintrag bearbeiten' : 'Stunden nachtragen' ?></h3>
    <form method="post" action="<?= $entryForEdit ? 'admin_update_time_entry.php' : 'time_entry_save.php' ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <?php if ($entryForEdit): ?>
            <input type="hidden" name="entry_id" value="<?= (int) $entryForEdit['id'] ?>">
        <?php endif; ?>
        <div class="grid">
            <div><label>Benutzer</label><select name="user_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $formData['user_id'] === (int) $user['id'] ? 'selected' : '' ?>><?= h($user['name'] . ' (' . $user['role'] . ')') ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Datum</label><input type="date" name="work_date" required value="<?= h($formData['work_date']) ?>"></div>

            <div>
                <label>Von</label>
                <div class="time-row">
                    <select data-time-hour="start_time"><?php foreach ($hours as $hour): $hh = sprintf('%02d', $hour); ?><option value="<?= $hh ?>" <?= $startHour === $hh ? 'selected' : '' ?>><?= $hh ?></option><?php endforeach; ?></select>
                    <select data-time-minute="start_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>" <?= $startMinute === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input type="hidden" name="start_time" data-time-hidden="start_time" value="<?= h($formData['start_time']) ?>">
            </div>

            <div>
                <label>Bis</label>
                <div class="time-row">
                    <select data-time-hour="end_time"><?php foreach ($hours as $hour): $hh = sprintf('%02d', $hour); ?><option value="<?= $hh ?>" <?= $endHour === $hh ? 'selected' : '' ?>><?= $hh ?></option><?php endforeach; ?></select>
                    <select data-time-minute="end_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>" <?= $endMinute === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input type="hidden" name="end_time" data-time-hidden="end_time" value="<?= h($formData['end_time']) ?>">
            </div>

            <div><label>Pause (Min.)</label><input type="number" name="break_minutes" min="0" value="<?= (int) $formData['break_minutes'] ?>" required></div>
            <div><label>Baustelle</label><select name="project_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= (int) $formData['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= h('#' . $project['id'] . ' - ' . $project['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Notiz</label><input name="notes" maxlength="200" value="<?= h((string) $formData['notes']) ?>"></div>
        </div>
        <p><button type="submit"><?= $entryForEdit ? 'Änderungen speichern' : 'Nachtragen' ?></button></p>
        <?php if ($entryForEdit): ?>
            <p><a class="btn" href="admin_time_entry.php">Bearbeitung abbrechen</a></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>Erfasste Stunden (bearbeitbar)</h3>
    <form method="get" class="grid">
        <div><label>Monat</label><input type="month" name="filter_month" value="<?= h($filterMonth) ?>"></div>
        <div><label>Benutzer</label><select name="filter_user_id"><option value="">Alle</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $filterUserId === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
    </form>

    <div class="table-wrap"><table>
        <tr><th>ID</th><th>Benutzer</th><th>Datum</th><th>Von</th><th>Bis</th><th>Pause</th><th>Baustelle</th><th>Notiz</th><th>Aktion</th></tr>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= (int) $entry['id'] ?></td>
                <td><?= h($entry['user_name']) ?></td>
                <td><?= h($entry['work_date']) ?></td>
                <td><?= h($entry['start_time']) ?></td>
                <td><?= h($entry['end_time']) ?></td>
                <td><?= (int) $entry['break_minutes'] ?> Min.</td>
                <td><?= h($entry['project_name']) ?></td>
                <td><?= h((string) $entry['notes']) ?></td>
                <td><a class="btn" href="admin_time_entry.php?entry_id=<?= (int) $entry['id'] ?>&filter_month=<?= h($filterMonth) ?>&filter_user_id=<?= (int) $filterUserId ?>">Bearbeiten</a></td>
            </tr>
        <?php endforeach; ?>
    </table></div>
</div>

<script>
(function() {
    function syncField(key) {
        var hourSelect = document.querySelector('[data-time-hour="' + key + '"]');
        var minuteSelect = document.querySelector('[data-time-minute="' + key + '"]');
        var hidden = document.querySelector('[data-time-hidden="' + key + '"]');
        if (!hourSelect || !minuteSelect || !hidden) return;
        hidden.value = hourSelect.value + ':' + minuteSelect.value;
    }

    ['start_time', 'end_time'].forEach(function(key) {
        var hourSelect = document.querySelector('[data-time-hour="' + key + '"]');
        var minuteSelect = document.querySelector('[data-time-minute="' + key + '"]');
        if (hourSelect) hourSelect.addEventListener('change', function() { syncField(key); });
        if (minuteSelect) minuteSelect.addEventListener('change', function() { syncField(key); });
        syncField(key);
    });
})();
</script>
<?php render_footer(); ?>
