<?php
require_once __DIR__ . '/view.php';
require_admin();

render_header('Admin Übersicht');
?>
<div class="card">
    <h3>Admin-Masken</h3>
    <div class="nav-grid">
        <a class="card" href="admin_users.php"><strong>Benutzer anlegen</strong><br><span class="small">Mitarbeiter/Admins erstellen</span></a>
        <a class="card" href="admin_projects.php"><strong>Baustellen</strong><br><span class="small">Anlegen, bearbeiten, zuweisen, filtern</span></a>
        <a class="card" href="admin_time_entry.php"><strong>Stunden nachtragen</strong><br><span class="small">Zeiten nachtragen und bestehende Einträge bearbeiten</span></a>
        <a class="card" href="admin_totals.php"><strong>Gesamtstunden</strong><br><span class="small">Monatsfilter + CSV Export</span></a>
    </div>
</div>
<?php render_footer(); ?>
