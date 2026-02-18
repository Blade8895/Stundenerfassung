<?php
require_once __DIR__ . '/view.php';
require_login();

$user = current_user();
render_header('Profil');
?>
<div class="card">
    <h3>Mein Profil</h3>
    <p><strong>Name:</strong> <?= h($user['name']) ?></p>
    <p><strong>E-Mail:</strong> <?= h($user['email']) ?></p>
</div>

<div class="card">
    <h3>Passwort ändern</h3>
    <form method="post" action="user_change_password.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <div><label>Aktuelles Passwort</label><input type="password" name="current_password" required></div>
            <div><label>Neues Passwort</label><input type="password" name="new_password" minlength="8" required></div>
            <div><label>Neues Passwort wiederholen</label><input type="password" name="confirm_password" minlength="8" required></div>
        </div>
        <p><button type="submit">Passwort speichern</button></p>
    </form>
</div>
<?php render_footer(); ?>
