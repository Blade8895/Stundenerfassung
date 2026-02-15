<?php
require_once __DIR__ . '/view.php';
require_admin();

$totals = db()->query(
    'SELECT u.id, u.name, u.email,
            COALESCE(SUM((CAST(substr(te.end_time,1,2) AS INTEGER)*60 + CAST(substr(te.end_time,4,2) AS INTEGER)) -
                         (CAST(substr(te.start_time,1,2) AS INTEGER)*60 + CAST(substr(te.start_time,4,2) AS INTEGER)) -
                         te.break_minutes), 0) AS total_minutes
     FROM users u
     LEFT JOIN time_entries te ON te.user_id = u.id
     WHERE u.role = "employee" AND u.active = 1
     GROUP BY u.id, u.name, u.email
     ORDER BY u.name'
)->fetchAll();

render_header('Admin Übersicht');
?>
<div class="card">
    <h3>Admin-Masken</h3>
    <div class="nav-grid">
        <a class="card" href="admin_users.php"><strong>Benutzer anlegen</strong><br><span class="small">Mitarbeiter/Admins erstellen</span></a>
        <a class="card" href="admin_projects.php"><strong>Baustellen</strong><br><span class="small">Anlegen, bearbeiten, zuweisen, filtern</span></a>
        <a class="card" href="admin_time_entry.php"><strong>Stunden nachtragen</strong><br><span class="small">Zeiten für alle Benutzer erfassen</span></a>
    </div>
</div>

<div class="card">
    <h3>Gesamtstunden pro Mitarbeiter</h3>
    <table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Gesamtstunden</th></tr>
        <?php foreach ($totals as $row): ?>
            <tr>
                <td><?= (int) $row['id'] ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h($row['email']) ?></td>
                <td><?= h(format_hours(max(0, ((int) $row['total_minutes']) / 60))) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
