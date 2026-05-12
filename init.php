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
        try {
            ensure_database_prerequisites((string) ($config['dsn'] ?? ''));
            $pdo = new PDO(
                $config['dsn'],
                $config['db_user'],
                $config['db_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->exec('PRAGMA busy_timeout = 5000');
            }

            migrate($pdo);
        } catch (Throwable $e) {
            render_startup_error($e);
            exit;
        }
    }

    return $pdo;
}

function ensure_database_prerequisites(string $dsn): void
{
    if (strpos($dsn, 'sqlite:') !== 0) {
        return;
    }

    $sqlitePath = substr($dsn, 7);
    if ($sqlitePath === '') {
        throw new RuntimeException('SQLite-DSN ist leer.');
    }

    $dir = dirname($sqlitePath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('SQLite-Verzeichnis konnte nicht erstellt werden: ' . $dir);
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('SQLite-Verzeichnis ist nicht beschreibbar: ' . $dir);
    }
}

function render_startup_error(Throwable $e): void
{
    http_response_code(500);
    $message = h($e->getMessage());

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Stundenerfassung - Konfigurationsfehler</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:780px;margin:30px auto;padding:0 16px}pre{background:#f4f4f4;border:1px solid #ddd;padding:10px;overflow:auto}</style>';
    echo '</head><body>';
    echo '<h2>Stundenerfassung konnte nicht starten</h2>';
    echo '<p>Die Anfrage konnte nicht verarbeitet werden, weil die Datenbank-Konfiguration nicht funktioniert.</p>';
    echo '<p><strong>Technischer Hinweis:</strong> ' . $message . '</p>';
    echo '<h3>Häufige Ursachen auf Webspace</h3>';
    echo '<ul>';
    echo '<li><code>data/</code> bzw. das SQLite-Zielverzeichnis hat keine Schreibrechte.</li>';
    echo '<li>PDO-Treiber fehlt (z. B. <code>pdo_sqlite</code> oder <code>pdo_mysql</code>).</li>';
    echo '<li><code>config.php</code> enthält falsche Zugangsdaten oder einen ungültigen DSN.</li>';
    echo '</ul>';
    echo '<p>Bitte README-Setup prüfen und danach Seite neu laden.</p>';
    echo '</body></html>';
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

function strtolower_safe(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function get_theme(): string
{
    $theme = $_COOKIE['theme'] ?? ($_SESSION['theme'] ?? 'light');
    return in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
}

function set_theme(string $theme): void
{
    $validatedTheme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    $_SESSION['theme'] = $validatedTheme;

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('theme', $validatedTheme, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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


function is_quarter_time(string $time): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    [$h, $m] = array_map('intval', explode(':', $time));
    if ($h < 0 || $h > 23) {
        return false;
    }

    return in_array($m, [0, 15, 30, 45], true);
}

function minutes_between(string $start, string $end): int
{
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    return ($eh * 60 + $em) - ($sh * 60 + $sm);
}

function format_hours(float $hours): string
{
    $roundedToQuarter = round($hours * 4) / 4;
    return number_format($roundedToQuarter, 2, ',', '.') . ' h';
}

function migrate(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $queries = [
            'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM("admin", "employee", "trainee") NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                max_weekly_minutes INT NOT NULL DEFAULT 2400
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL UNIQUE,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS user_projects (
                user_id INT NOT NULL,
                project_id INT NOT NULL,
                PRIMARY KEY (user_id, project_id),
                CONSTRAINT fk_user_projects_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_projects_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS time_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                project_id INT NOT NULL,
                work_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                break_minutes INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_by_user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_time_entries_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_time_entries_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_time_entries_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_time_entries_user_date (user_id, work_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS overtime_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                adjustment_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                minutes_delta INT NOT NULL,
                notes TEXT NULL,
                created_by_user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_overtime_adj_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_overtime_adj_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_overtime_adj_user_date (user_id, adjustment_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS project_billing_cuts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                cutoff_from_date DATE NOT NULL,
                invoice_number VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                created_by_user_id INT NOT NULL,
                CONSTRAINT fk_project_billing_cuts_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_billing_cuts_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_project_billing_cuts_project_date (project_id, cutoff_from_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    } else {
        $queries = [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ("admin", "employee", "trainee")),
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                max_weekly_minutes INTEGER NOT NULL DEFAULT 2400
            )',
            'CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_projects (
                user_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, project_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            )',
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
            )',
            'CREATE TABLE IF NOT EXISTS overtime_adjustments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                adjustment_date TEXT NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                minutes_delta INTEGER NOT NULL,
                notes TEXT,
                created_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS project_billing_cuts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                cutoff_from_date TEXT NOT NULL,
                invoice_number TEXT NOT NULL,
                created_at TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        ];
    }

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    ensure_supported_roles($pdo, $driver);
    ensure_max_weekly_minutes_column($pdo, $driver);
}

function ensure_max_weekly_minutes_column(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS max_weekly_minutes INT NOT NULL DEFAULT 2400');
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'max_weekly_minutes') {
            return;
        }
    }

    $pdo->exec('ALTER TABLE users ADD COLUMN max_weekly_minutes INTEGER NOT NULL DEFAULT 2400');
}

function ensure_supported_roles(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec('ALTER TABLE users MODIFY COLUMN role ENUM("admin", "employee", "trainee") NOT NULL');
        return;
    }

    if ($driver !== 'sqlite') {
        return;
    }

    $tableSqlStmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'");
    $tableSql = (string) ($tableSqlStmt ? $tableSqlStmt->fetchColumn() : '');
    if ($tableSqlStmt instanceof PDOStatement) {
        $tableSqlStmt->closeCursor();
    }

    if (strtolower_safe($tableSql) === '' || strpos(strtolower_safe($tableSql), 'trainee') !== false) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');

    try {
        $pdo->beginTransaction();
        $pdo->exec('CREATE TABLE users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ("admin", "employee", "trainee")),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('INSERT INTO users_new (id, name, email, password_hash, role, active, created_at)
            SELECT id, name, email, password_hash, role, active, created_at FROM users');
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
