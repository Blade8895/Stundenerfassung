<?php
require_once __DIR__ . '/view.php';
require_admin();

$users = db()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY role DESC, name')->fetchAll();
$projects = db()->query('SELECT id, name FROM projects WHERE active = 1 ORDER BY name')->fetchAll();
$hours = range(0, 23);
$quarterMinutes = ['00', '15', '30', '45'];

render_header('Admin - Stunden nachtragen');
?>
<div class="card">
    <h3>Stunden nachtragen</h3>
    <form method="post" action="time_entry_save.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <div><label>Benutzer</label><select name="user_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>"><?= h($user['name'] . ' (' . $user['role'] . ')') ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Datum</label><input type="date" name="work_date" required></div>

            <div>
                <label>Von</label>
                <div class="time-row">
                    <select data-time-hour="start_time"><?php foreach ($hours as $hour): ?><option value="<?= sprintf('%02d', $hour) ?>"><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
                    <select data-time-minute="start_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input type="hidden" name="start_time" data-time-hidden="start_time" value="08:00">
            </div>

            <div>
                <label>Bis</label>
                <div class="time-row">
                    <select data-time-hour="end_time"><?php foreach ($hours as $hour): ?><option value="<?= sprintf('%02d', $hour) ?>" <?= $hour === 16 ? 'selected' : '' ?>><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
                    <select data-time-minute="end_time"><?php foreach ($quarterMinutes as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?></select>
                </div>
                <input type="hidden" name="end_time" data-time-hidden="end_time" value="16:00">
            </div>

            <div><label>Pause (Min.)</label><input type="number" name="break_minutes" min="0" value="0" required></div>
            <div><label>Baustelle</label><select name="project_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"><?= h('#' . $project['id'] . ' - ' . $project['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Notiz</label><input name="notes" maxlength="200"></div>
        </div>
        <p><button type="submit">Nachtragen</button></p>
    </form>
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
