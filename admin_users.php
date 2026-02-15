<?php
require_once __DIR__ . '/view.php';
require_admin();

$users = db()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY role DESC, name')->fetchAll();

render_header('Admin - Benutzer');
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
    <h3>Benutzerübersicht</h3>
    <table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Angelegt</th></tr>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= (int) $user['id'] ?></td>
                <td><?= h($user['name']) ?></td>
                <td><?= h($user['email']) ?></td>
                <td><?= h($user['role']) ?></td>
                <td><?= h($user['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php render_footer(); ?>
