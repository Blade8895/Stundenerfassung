<?php
require_once __DIR__ . '/init.php';

function render_header(string $title): void
{
    ensure_admin_exists();
    $user = current_user();
    $theme = get_theme();

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . h(app_name() . ' - ' . $title) . '</title><link rel="stylesheet" href="style.css"></head><body class="theme-' . h($theme) . '">';
    echo '<header><h2>' . h(app_name()) . '</h2>';
    if ($user) {
        echo '<nav><a href="dashboard.php">Dashboard</a>';
        if (is_admin()) {
            echo ' | <a href="admin.php">Admin</a>';
        }
        $targetTheme = $theme === 'dark' ? 'light' : 'dark';
        echo ' | <a href="theme_toggle.php?theme=' . h($targetTheme) . '">Theme: ' . h(strtoupper($targetTheme)) . '</a>';
        echo ' | <a href="user_settings.php">Profil</a>';
        echo ' | <a href="logout.php">Logout</a></nav>';
    }
    echo '</header>';

    foreach (get_flashes() as $flash) {
        echo '<div class="flash-' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    }
}

function render_footer(): void
{
    echo '</body></html>';
}
