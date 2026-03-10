# Stundenerfassung – Node.js Modern SaaS Edition

Diese Version ist vollständig auf eine moderne Node.js-Webapp umgestellt und nutzt weiterhin dieselbe SQLite-Struktur (`users`, `projects`, `user_projects`, `time_entries`) für volle Kompatibilität mit bestehenden Datenbanken.

## Tech Stack

- Node.js + Express
- EJS-Templates
- SQLite über `better-sqlite3`
- Session-Auth über `express-session`
- Modernes responsives SaaS-Layout (Sidebar + Topbar + Content)

## Start

```bash
npm install
npm start
```

App läuft anschließend auf `http://localhost:3000`.

## Setup / Login

1. Wenn die DB leer ist: `GET /setup` aufrufen und den ersten Admin anlegen.
2. Danach über `/login` anmelden.

## SQLite-Kompatibilität

- Bestehende SQLite-Dateien bleiben nutzbar, solange sie das vorhandene Schema verwenden.
- Pfad zur Datenbank standardmäßig: `data/stundenerfassung.sqlite`
- Optional anpassbar über `DB_PATH` Umgebungsvariable.

## Features

- Login + Session
- Dashboard mit Monatsfilter und Gesamtsumme
- Zeiterfassung (Projekt, Datum, Start/Ende, Pause, Notiz)
- Reports mit strukturierter Tabelle
- Admin-Bereich für Benutzer, Projekte, Projektzuweisungen und Summen

## Hinweise

- Das alte PHP-System liegt noch im Repository, wird aber nicht mehr für den Betrieb benötigt.
- Für Produktion: `SESSION_SECRET` setzen.
