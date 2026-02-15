<?php
require_once __DIR__ . '/view.php';
ensure_admin_exists();

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email AND active = 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        flash('success', 'Willkommen, ' . $user['name'] . '!');
        header('Location: dashboard.php');
        exit;
    }

    flash('error', 'Login fehlgeschlagen.');
}

render_header('Login');
?>
<div class="card">
    <h3>Login</h3>
    <form method="post">
        <div class="grid">
            <div><label>E-Mail</label><input type="email" name="email" required></div>
            <div><label>Passwort</label><input type="password" name="password" required></div>
        </div>
        <p><button type="submit">Einloggen</button></p>
    </form>
</div>
<?php render_footer(); ?>
