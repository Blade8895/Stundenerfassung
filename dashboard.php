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

$hours = range(0, 23);
$quarterMinutes = ['00', '15', '30', '45'];

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

            <div>
                <label>Von</label>
                <div class="time-row">
                    <select data-time-hour="start_time"><?php foreach ($hours as $hour): ?><option value="<?= sprintf('%02d', $hour) ?>"><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
                    <select data-time-minute="start_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input class="time-manual" data-time-manual="start_time" placeholder="HH:MM (auto 00/15/30/45)">
                <input type="hidden" name="start_time" data-time-hidden="start_time" value="08:00">
            </div>

            <div>
                <label>Bis</label>
                <div class="time-row">
                    <select data-time-hour="end_time"><?php foreach ($hours as $hour): ?><option value="<?= sprintf('%02d', $hour) ?>" <?= $hour === 16 ? 'selected' : '' ?>><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
                    <select data-time-minute="end_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input class="time-manual" data-time-manual="end_time" placeholder="HH:MM (auto 00/15/30/45)">
                <input type="hidden" name="end_time" data-time-hidden="end_time" value="16:00">
            </div>

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
<script>
(function() {
    function roundToQuarter(h, m) {
        var total = h * 60 + m;
        var rounded = Math.round(total / 15) * 15;
        if (rounded < 0) rounded = 0;
        if (rounded > (23 * 60 + 45)) rounded = 23 * 60 + 45;
        return [Math.floor(rounded / 60), rounded % 60];
    }

    function pad(v) { return String(v).padStart(2, '0'); }

    function syncField(key) {
        var hourSelect = document.querySelector('[data-time-hour="' + key + '"]');
        var minuteSelect = document.querySelector('[data-time-minute="' + key + '"]');
        var hidden = document.querySelector('[data-time-hidden="' + key + '"]');
        if (!hourSelect || !minuteSelect || !hidden) return;
        hidden.value = hourSelect.value + ':' + minuteSelect.value;
    }

    function applyManual(key, value) {
        var match = /^\s*(\d{1,2}):(\d{1,2})\s*$/.exec(value || '');
        if (!match) return;
        var h = Math.max(0, Math.min(23, parseInt(match[1], 10)));
        var m = Math.max(0, Math.min(59, parseInt(match[2], 10)));
        var rounded = roundToQuarter(h, m);

        var hh = pad(rounded[0]);
        var mm = pad(rounded[1]);

        var hourSelect = document.querySelector('[data-time-hour="' + key + '"]');
        var minuteSelect = document.querySelector('[data-time-minute="' + key + '"]');
        var manual = document.querySelector('[data-time-manual="' + key + '"]');
        if (hourSelect) hourSelect.value = hh;
        if (minuteSelect) minuteSelect.value = mm;
        if (manual) manual.value = hh + ':' + mm;
        syncField(key);
    }

    ['start_time', 'end_time'].forEach(function(key) {
        var hourSelect = document.querySelector('[data-time-hour="' + key + '"]');
        var minuteSelect = document.querySelector('[data-time-minute="' + key + '"]');
        var manual = document.querySelector('[data-time-manual="' + key + '"]');

        if (hourSelect) hourSelect.addEventListener('change', function() { syncField(key); });
        if (minuteSelect) minuteSelect.addEventListener('change', function() { syncField(key); });
        if (manual) {
            manual.addEventListener('blur', function() { applyManual(key, manual.value); });
            manual.addEventListener('change', function() { applyManual(key, manual.value); });
        }

        applyManual(key, (document.querySelector('[data-time-hidden="' + key + '"]') || {}).value || '00:00');
    });
})();
</script>
<?php render_footer(); ?>
