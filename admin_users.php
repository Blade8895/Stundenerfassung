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
            <div><label>Rolle</label><select name="role"><option value="employee">Mitarbeiter</option><option value="trainee">Auszubildender</option><option value="admin">Admin</option></select></div>
        </div>
        <p><button type="submit">Benutzer speichern</button></p>
    </form>
</div>

<div class="card">
    <h3>Benutzer bearbeiten</h3>
    <div class="table-wrap"><table>
        <tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Passwort</th><th>Angelegt</th><th>Aktion</th></tr>
        <?php foreach ($users as $user): ?>
            <?php $formId = 'user-form-' . (int) $user['id']; ?>
            <tr>
                <td><?= (int) $user['id'] ?></td>
                <td><input form="<?= h($formId) ?>" name="name" value="<?= h($user['name']) ?>" required></td>
                <td><input form="<?= h($formId) ?>" type="email" name="email" value="<?= h($user['email']) ?>" required></td>
                <td>
                    <select form="<?= h($formId) ?>" name="role">
                        <option value="employee" <?= $user['role'] === 'employee' ? 'selected' : '' ?>>Mitarbeiter</option>
                        <option value="trainee" <?= $user['role'] === 'trainee' ? 'selected' : '' ?>>Auszubildender</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </td>
                <td><input form="<?= h($formId) ?>" type="password" name="password" minlength="8" placeholder="leer lassen"></td>
                <td><?= h($user['created_at']) ?></td>
                <td>
                    <form id="<?= h($formId) ?>" method="post" action="admin_update_user.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button type="submit">Speichern</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table></div>
    <p class="small">Passwort nur setzen, wenn es geändert werden soll (mind. 8 Zeichen).</p>
</div>
<?php render_footer(); ?>
