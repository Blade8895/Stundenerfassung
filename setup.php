<?php
require_once __DIR__ . '/view.php';

$count = (int) db()->query('SELECT COUNT(*) AS c FROM users WHERE role = "admin"')->fetch()['c'];
if ($count > 0) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || strlen($password) < 8) {
        flash('error', 'Bitte alle Felder ausfüllen. Passwort mindestens 8 Zeichen.');
    } else {
        $stmt = db()->prepare('INSERT INTO users(name, email, password_hash, role, active, created_at) VALUES(:name, :email, :password_hash, "admin", 1, :created_at)');
        $stmt->execute([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => now(),
        ]);
        flash('success', 'Admin wurde angelegt. Bitte einloggen.');
        header('Location: login.php');
        exit;
    }
}

render_header('Setup');
?>
<div class="card">
    <h3>Erstkonfiguration</h3>
    <p>Lege den ersten Administrator an.</p>
    <form method="post">
        <div class="grid">
            <div><label>Name</label><input name="name" required></div>
            <div><label>E-Mail</label><input type="email" name="email" required></div>
            <div><label>Passwort</label><input type="password" name="password" minlength="8" required></div>
        </div>
        <p><button type="submit">Admin erstellen</button></p>
    </form>
</div>
<?php render_footer(); ?>
