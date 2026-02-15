# Stundenerfassung (PHP + SQL)

Webspace-fähige Zeiterfassung mit:

- Login + Benutzerverwaltung (Admin/Mitarbeiter)
- Baustellenverwaltung und Zuweisung pro Benutzer (inkl. Admins)
- Zeiterfassung mit `von`/`bis`, Pause, Notiz
- Dashboard mit Monatsfilter und Gesamtstunden
- Admin kann Stunden für Benutzer nachtragen
- SQL-Speicherung via PDO (SQLite oder MySQL)
- Optionaler Light-/Darkmode

## Setup-Anleitung (Schritt für Schritt)

### 1) Voraussetzungen prüfen

Auf dem Webspace muss verfügbar sein:

- PHP 8.0+ (empfohlen)
- `PDO` Erweiterung
- Datenbanktreiber:
  - entweder `pdo_sqlite` (für SQLite),
  - oder `pdo_mysql` (für MySQL/MariaDB)

### 2) Dateien hochladen

- Alle Projektdateien in ein Verzeichnis auf dem Webspace kopieren.
- Falls möglich, dieses Verzeichnis als Webroot verwenden (oder per Domain/Subdomain darauf zeigen).

### 3) `config.php` konfigurieren

Standardmäßig ist SQLite aktiv:

```php
'dsn' => 'sqlite:' . __DIR__ . '/data/stundenerfassung.sqlite',
'db_user' => null,
'db_pass' => null,
```

Für MySQL/MariaDB stattdessen:

```php
'dsn' => 'mysql:host=localhost;dbname=stundenerfassung;charset=utf8mb4',
'db_user' => 'DEIN_DB_USER',
'db_pass' => 'DEIN_DB_PASSWORT',
```

### 4) Schreibrechte setzen (wichtig bei SQLite)

Bei SQLite muss das Zielverzeichnis beschreibbar sein:

- Verzeichnis `data/` muss existieren.
- PHP-Webserver-Benutzer braucht Schreibrechte auf `data/`.

Typischer Linux-Befehl:

```bash
chmod 775 data
```

### 5) Erst-Setup aufrufen

Im Browser öffnen:

- `https://DEINE-DOMAIN/setup.php`

Dort den ersten Admin anlegen.

### 6) Admin-Masken nutzen

`Admin` ist jetzt in mehrere eigene Masken getrennt:

- `admin.php` → Übersicht
- `admin_users.php` → Benutzer anlegen
- `admin_projects.php` → Baustellen anlegen/bearbeiten + Zuweisung + Filter (Name, Eintragungsdatum, letzte Aktivität)
- `admin_time_entry.php` → Stunden nachtragen und bestehende Einträge bearbeiten
- `admin_totals.php` → Gesamtstunden pro Mitarbeiter mit Monatsfilter + CSV Export
- `admin_employee_details.php` → Detailansicht je Mitarbeiter (Baustelle, Notizen, einzelne Buchungen)

### 7) Darkmode

Im Header kann per `Theme: DARK/LIGHT` umgeschaltet werden.
Darkmode-Farben:

- Hintergrund: `#0B0D10`
- Boxen: `#12151B`
- Text: `#E8ECF1`

## Häufiges Problem: „Die Anfrage kann nicht bearbeitet werden“

Das passiert meist bei einem Serverfehler (HTTP 500). Häufige Ursachen:

1. **SQLite-Verzeichnis nicht beschreibbar**
2. **PDO-Treiber fehlt**
3. **Falscher DSN / falsche Zugangsdaten**

Die Anwendung zeigt bei DB-Startfehlern zusätzlich eine konkrete Fehlermeldung mit Hinweisen an.


## Mobile-Optimierung

- Für kleine Displays (`<=640px`) wurde ein mobiles Layout ergänzt (Navigation-Umbruch, 1-spaltige Formulare, scrollbare Tabellen), um Überlappungen zu vermeiden.
- In `Dashboard -> Meine Zeiten` wurde die Spalte `Erfasst von` entfernt.


## Regeln für Zeiterfassung

- Mitarbeiter können eigene Zeiten nur für den **heutigen Tag** erfassen (kein rückwirkendes Datum im Mitarbeiter-Dashboard).
- Rückwirkende Einträge erfolgen über **Admin -> Stunden nachtragen**.
- Zeitauswahl ist auf **15-Minuten-Schritte** begrenzt (`00`, `15`, `30`, `45`).
