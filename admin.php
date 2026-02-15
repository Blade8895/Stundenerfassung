<?php
require_once __DIR__ . '/view.php';
require_admin();

$employees = db()->query('SELECT id, name, email, role, active FROM users ORDER BY role DESC, name')->fetchAll();
$projects = db()->query('SELECT id, name, active FROM projects ORDER BY name')->fetchAll();
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
$assignments = [];
if ($selectedUserId > 0) {
    $stmt = db()->prepare('SELECT project_id FROM user_projects WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $selectedUserId]);
    foreach ($stmt->fetchAll() as $r) {
        $assignments[] = (int) $r['project_id'];
    }
}

render_header('Adminbereich');
?>
<div class="card">
    <h3>Benutzer anlegen</h3>
    <form method="post" action="admin_save_user.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <div><label>Name</label><input name="name" required></div>
            <div><label>E-Mail</label><input type="email" name="email" required></div>
            <div><label>Passwort</label><input type="password" name="password" minlength="8" required></div>
            <div><label>Rolle</label><select name="role"><option value="employee">Mitarbeiter</option><option value="admin">Admin</option></select></div>
        </div>
        <p><button type="submit">Benutzer speichern</button></p>
    </form>
</div>

<div class="card">
    <h3>Baustelle anlegen</h3>
    <form method="post" action="admin_save_project.php" class="grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div><label>Name</label><input name="name" required></div>
        <div style="align-self:end"><button type="submit">Baustelle speichern</button></div>
    </form>
</div>

<div class="card">
    <h3>Baustellen-Zuweisung</h3>
    <form method="get" class="grid">
        <div>
            <label>Mitarbeiter</label>
            <select name="user_id" onchange="this.form.submit()">
                <option value="">Bitte wählen</option>
                <?php foreach ($employees as $employee): ?>
                    <?php if ($employee['role'] !== 'employee') continue; ?>
                    <option value="<?= (int) $employee['id'] ?>" <?= $selectedUserId === (int) $employee['id'] ? 'selected' : '' ?>><?= h($employee['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if ($selectedUserId > 0): ?>
        <form method="post" action="admin_assign_projects.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
            <div class="grid">
                <?php foreach ($projects as $project): ?>
                    <label><input type="checkbox" name="project_ids[]" value="<?= (int) $project['id'] ?>" <?= in_array((int) $project['id'], $assignments, true) ? 'checked' : '' ?>> <?= h($project['name']) ?></label>
                <?php endforeach; ?>
            </div>
            <p><button type="submit">Zuweisung speichern</button></p>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Stunden für Mitarbeiter nachtragen</h3>
    <form method="post" action="time_entry_save.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <div><label>Mitarbeiter</label><select name="user_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($employees as $employee): ?>
                    <?php if ($employee['role'] !== 'employee') continue; ?>
                    <option value="<?= (int) $employee['id'] ?>"><?= h($employee['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label>Datum</label><input type="date" name="work_date" required></div>
            <div><label>Von</label><input type="time" name="start_time" required></div>
            <div><label>Bis</label><input type="time" name="end_time" required></div>
            <div><label>Pause</label><input type="number" name="break_minutes" min="0" value="0" required></div>
            <div><label>Baustelle-ID</label><input type="number" name="project_id" min="1" required><div class="small">Tipp: IDs aus Tabelle unten verwenden.</div></div>
            <div><label>Notiz</label><input name="notes" maxlength="200"></div>
        </div>
        <p><button type="submit">Nachtragen</button></p>
    </form>
</div>

<div class="card">
    <h3>Übersicht</h3>
    <table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th></tr>
        <?php foreach ($employees as $employee): ?>
            <tr><td><?= (int)$employee['id'] ?></td><td><?= h($employee['name']) ?></td><td><?= h($employee['email']) ?></td><td><?= h($employee['role']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <br>
    <table>
        <tr><th>ID</th><th>Baustelle</th></tr>
        <?php foreach ($projects as $project): ?>
            <tr><td><?= (int)$project['id'] ?></td><td><?= h($project['name']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
