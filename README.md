# Stundenerfassung - Node.js Modern SaaS Edition

Diese Version ist vollstaendig auf eine moderne Node.js-Webapp umgestellt und nutzt weiterhin dieselbe SQLite-Struktur (`users`, `projects`, `user_projects`, `time_entries`) fuer volle Kompatibilitaet mit bestehenden Datenbanken.

## Tech Stack

- Node.js + Express
- EJS-Templates
- SQLite ueber `better-sqlite3`
- Session-Auth ueber `express-session`
- Modernes responsives SaaS-Layout (Sidebar + Topbar + Content)

## Start

```bash
npm install
npm start
```

App laeuft anschliessend auf `http://localhost:3000`.

## Setup / Login

1. Wenn die DB leer ist: `GET /setup` aufrufen und den ersten Admin anlegen.
2. Danach ueber `/login` anmelden.

## SQLite-Kompatibilitaet

- Bestehende SQLite-Dateien bleiben nutzbar, solange sie das vorhandene Schema verwenden.
- Pfad zur Datenbank standardmaessig: `data/stundenerfassung.sqlite`
- Optional anpassbar ueber `DB_PATH` Umgebungsvariable.

## Features

- Login + Session
- Dashboard mit Monatsfilter und Gesamtsumme
- Zeiterfassung (Projekt, Datum, Start/Ende, Pause, Notiz)
- Reports mit strukturierter Tabelle
- Admin-Bereich fuer Benutzer, Projekte, Projektzuweisungen und Summen

## Hinweise

- Das Repository enthaelt nur noch die Node.js-Webapp.
- Fuer Produktion: `SESSION_SECRET` setzen.
