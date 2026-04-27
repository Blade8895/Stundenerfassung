<?php
require_once __DIR__ . '/view.php';
require_admin();

$nameFilter = trim($_GET['name'] ?? '');
$createdFrom = trim($_GET['created_from'] ?? '');
$createdTo = trim($_GET['created_to'] ?? '');
$sort = $_GET['sort'] ?? 'name_asc';

$allowedSort = [
    'name_asc' => 'p.name ASC',
    'name_desc' => 'p.name DESC',
    'created_desc' => 'p.created_at DESC',
    'created_asc' => 'p.created_at ASC',
    'last_activity_desc' => 'last_activity DESC',
    'last_activity_asc' => 'last_activity ASC',
];
$orderBy = $allowedSort[$sort] ?? $allowedSort['name_asc'];

$where = [];
$params = [];
if ($nameFilter !== '') {
    $where[] = 'p.name LIKE :name';
    $params['name'] = '%' . $nameFilter . '%';
}
if ($createdFrom !== '') {
    $where[] = 'substr(p.created_at, 1, 10) >= :created_from';
    $params['created_from'] = $createdFrom;
}
if ($createdTo !== '') {
    $where[] = 'substr(p.created_at, 1, 10) <= :created_to';
    $params['created_to'] = $createdTo;
}

$sql = 'SELECT p.id, p.name, p.created_at, MAX(te.work_date) AS last_activity
        FROM projects p
        LEFT JOIN time_entries te ON te.project_id = p.id';
if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY p.id, p.name, p.created_at ORDER BY ' . $orderBy;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$users = db()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY role DESC, name')->fetchAll();
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
$assignments = [];
if ($selectedUserId > 0) {
    $aStmt = db()->prepare('SELECT project_id FROM user_projects WHERE user_id = :user_id');
    $aStmt->execute(['user_id' => $selectedUserId]);
    foreach ($aStmt->fetchAll() as $r) {
        $assignments[] = (int) $r['project_id'];
    }
}

render_header('Admin - Baustellen');
?>
<div class="card">
    <h3>Baustelle anlegen / bearbeiten</h3>
    <form method="post" action="admin_save_project.php" class="grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div><label>Baustellen-ID (leer = neu)</label><input type="number" name="project_id" min="1"></div>
        <div><label>Name</label><input name="name" required></div>
        <div style="align-self:end"><button type="submit">Speichern</button></div>
    </form>
</div>

<div class="card">
    <h3>Baustellen-Zuweisung (Admins + Mitarbeiter)</h3>
    <form method="get" class="grid">
        <input type="hidden" name="name" value="<?= h($nameFilter) ?>">
        <input type="hidden" name="created_from" value="<?= h($createdFrom) ?>">
        <input type="hidden" name="created_to" value="<?= h($createdTo) ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <div>
            <label>Benutzer</label>
            <select name="user_id" onchange="this.form.submit()">
                <option value="">Bitte wählen</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? 'selected' : '' ?>><?= h($user['name'] . ' (' . $user['role'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if ($selectedUserId > 0): ?>
        <form method="post" action="admin_assign_projects.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
            <div class="project-assignment-grid">
                <?php foreach ($projects as $project): ?>
                    <?php $isChecked = in_array((int) $project['id'], $assignments, true); ?>
                    <label class="project-assignment-card <?= $isChecked ? 'is-selected' : '' ?>">
                        <input class="project-assignment-checkbox" type="checkbox" name="project_ids[]" value="<?= (int) $project['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                        <span><?= h('#' . $project['id'] . ' - ' . $project['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p><button type="submit">Zuweisung speichern</button></p>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Baustellen-Liste (ID einfach finden)</h3>
    <form method="get" class="grid">
        <div><label>Name</label><input name="name" value="<?= h($nameFilter) ?>" placeholder="z. B. Rohbau"></div>
        <div><label>Angelegt ab</label><input type="date" name="created_from" value="<?= h($createdFrom) ?>"></div>
        <div><label>Angelegt bis</label><input type="date" name="created_to" value="<?= h($createdTo) ?>"></div>
        <div><label>Sortierung</label>
            <select name="sort">
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>Eintragung neueste</option>
                <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>Eintragung älteste</option>
                <option value="last_activity_desc" <?= $sort === 'last_activity_desc' ? 'selected' : '' ?>>Letzte Aktivität neueste</option>
                <option value="last_activity_asc" <?= $sort === 'last_activity_asc' ? 'selected' : '' ?>>Letzte Aktivität älteste</option>
            </select>
        </div>
        <div style="align-self:end"><button type="submit">Filtern</button></div>
    </form>

    <table>
        <tr><th>ID</th><th>Name</th><th>Angelegt</th><th>Letzte Aktivität</th><th>Aktion</th></tr>
        <?php foreach ($projects as $project): ?>
            <tr>
                <td><?= (int) $project['id'] ?></td>
                <td><?= h($project['name']) ?></td>
                <td><?= h($project['created_at']) ?></td>
                <td><?= h($project['last_activity'] ?: '-') ?></td>
                <td><a class="btn" href="admin_project_details.php?project_id=<?= (int) $project['id'] ?>">Details</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<script>
(function() {
    var cards = document.querySelectorAll('.project-assignment-card');
    cards.forEach(function(card) {
        var checkbox = card.querySelector('.project-assignment-checkbox');
        if (!checkbox) return;

        function syncSelectedState() {
            card.classList.toggle('is-selected', checkbox.checked);
        }

        checkbox.addEventListener('change', syncSelectedState);
        syncSelectedState();
    });
})();
</script>
<?php render_footer(); ?>
