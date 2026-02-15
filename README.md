# Stundenerfassung (PHP + SQL)

Webspace-fähige Zeiterfassung mit:

- Login + Benutzerverwaltung (Admin/Mitarbeiter)
- Baustellenverwaltung und Zuweisung pro Mitarbeiter
- Zeiterfassung mit `von`/`bis`, Pause, Notiz
- Monatsauswertung für Mitarbeiter (Gesamtstunden)
- Admin kann Stunden für Mitarbeiter nachtragen
- SQL-Speicherung via PDO (SQLite oder MySQL)

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

Typischer Linux-Befehl (je nach Hosting ggf. via Dateimanager/FTP setzen):

```bash
chmod 775 data
```

### 5) Erst-Setup aufrufen

Im Browser öffnen:

- `https://DEINE-DOMAIN/setup.php`

Dort den ersten Admin anlegen.

### 6) Login und Nutzung

- Danach über `login.php` anmelden.
- Im Adminbereich Benutzer und Baustellen anlegen.
- Mitarbeiter Baustellen zuweisen.
- Mitarbeiter buchen Zeiten, Admin kann Zeiten nachtragen.

## Häufiges Problem: „Die Anfrage kann nicht bearbeitet werden“

Das passiert meist bei einem Serverfehler (HTTP 500). Häufige Ursachen:

1. **SQLite-Verzeichnis nicht beschreibbar**
   - `data/` Rechte prüfen.
2. **PDO-Treiber fehlt**
   - `pdo_sqlite` oder `pdo_mysql` ist nicht aktiv.
3. **Falscher DSN / falsche Zugangsdaten**
   - `config.php` prüfen.

Die Anwendung zeigt bei DB-Startfehlern jetzt zusätzlich eine konkrete Fehlermeldung mit Hinweisen an.

## Sicherheit / Validierung

- Passwort-Hashing mit `password_hash`
- Session-basierter Login
- CSRF-Schutz für Formulare
- Verhindert überlappende Zeitbuchungen pro Tag/Mitarbeiter
- Plausibilitätsprüfungen für Zeit/Pause
