<?php
$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['timezone'] ?? 'Europe/Berlin');

if (session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name'] ?? 'stundenerfassung_session');
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo === null) {
        $pdo = new PDO(
            $config['dsn'],
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        migrate($pdo);
    }

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ("admin", "employee")),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_projects (
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            break_minutes INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            created_by_user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
}

function app_name(): string
{
    global $config;
    return $config['app_name'] ?? 'Stundenerfassung';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    static $user = false;
    if ($user === false) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        if ($user === null) {
            unset($_SESSION['user_id']);
        }
    }

    return $user;
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        echo 'Keine Berechtigung.';
        exit;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Ungültiges CSRF-Token.');
    }
}

function ensure_admin_exists(): void
{
    $count = (int) db()->query('SELECT COUNT(*) AS c FROM users WHERE role = "admin"')->fetch()['c'];
    if ($count === 0 && basename($_SERVER['PHP_SELF']) !== 'setup.php') {
        header('Location: setup.php');
        exit;
    }
}

function minutes_between(string $start, string $end): int
{
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    return ($eh * 60 + $em) - ($sh * 60 + $sm);
}

function format_hours(float $hours): string
{
    return number_format($hours, 2, ',', '.') . ' h';
}
