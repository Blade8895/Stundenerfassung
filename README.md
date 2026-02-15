# Stundenerfassung (PHP + SQL)

Einfache webspace-fähige Stundenerfassung mit:

- Login + Benutzerverwaltung (Admin/Mitarbeiter)
- Baustellenverwaltung und Zuweisung pro Mitarbeiter
- Zeiterfassung mit `von`/`bis`, Pause, Notiz
- Monatsauswertung für Mitarbeiter (Gesamtstunden)
- Admin kann Stunden für Mitarbeiter nachtragen
- SQL-Speicherung via PDO (SQLite oder MySQL)

## Installation

1. Dateien auf den Webspace laden.
2. `config.php` anpassen (DSN/DB-Zugang).
3. Schreibrechte für `data/` setzen (bei SQLite).
4. Applikation aufrufen (`setup.php` erstellt den ersten Admin).

## Konfiguration (`config.php`)

Beispiel MySQL:

```php
'dsn' => 'mysql:host=localhost;dbname=stundenerfassung;charset=utf8mb4',
'db_user' => 'dbuser',
'db_pass' => 'dbpass',
```

Standard ist SQLite (`data/stundenerfassung.sqlite`).

## Standard-Features

- Passwort-Hashing mit `password_hash`
- Session-basierter Login
- CSRF-Schutz für Formulare
- Verhindert überlappende Zeitbuchungen pro Tag/Mitarbeiter
- Plausibilitätsprüfungen für Zeit/Pause
