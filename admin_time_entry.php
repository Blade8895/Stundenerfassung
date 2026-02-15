<?php
require_once __DIR__ . '/view.php';
require_admin();

$users = db()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY role DESC, name')->fetchAll();
$projects = db()->query('SELECT id, name FROM projects WHERE active = 1 ORDER BY name')->fetchAll();

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
            <div><label>Von</label><input type="time" name="start_time" required></div>
            <div><label>Bis</label><input type="time" name="end_time" required></div>
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
<?php render_footer(); ?>
