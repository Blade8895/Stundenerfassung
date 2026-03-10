process.env.TZ = process.env.TZ || 'Europe/Berlin';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const express = require('express');
const session = require('express-session');
const SQLiteStore = require('better-sqlite3-session-store')(session);
const bcrypt = require('bcryptjs');
const Database = require('better-sqlite3');

const APP_NAME = process.env.APP_NAME || 'Stundenerfassung';
const SESSION_NAME = process.env.SESSION_NAME || 'stundenerfassung_session';
const THEME_COOKIE_NAME = 'theme';
const HOURS = Array.from({ length: 24 }, (_, index) => String(index).padStart(2, '0'));
const QUARTER_MINUTES = ['00', '15', '30', '45'];
const DEFAULT_DB_PATH = process.env.DB_PATH || path.join(__dirname, '..', 'data', 'stundenerfassung.sqlite');
const CREATE_USERS_SQL = `
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin', 'employee', 'trainee')),
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );
`;
const CREATE_PROJECTS_SQL = `
  CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );
`;
const CREATE_USER_PROJECTS_SQL = `
  CREATE TABLE IF NOT EXISTS user_projects (
    user_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, project_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
  );
`;
const CREATE_TIME_ENTRIES_SQL = `
  CREATE TABLE IF NOT EXISTS time_entries (
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
  );
`;
const CREATE_INDEX_SQL = `
  CREATE INDEX IF NOT EXISTS idx_time_entries_user_date
  ON time_entries(user_id, work_date);
`;

function pad(value) {
  return String(value).padStart(2, '0');
}

function formatLocalDate(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function todayIsoDate() {
  return formatLocalDate(new Date());
}

function currentMonth() {
  return todayIsoDate().slice(0, 7);
}

function getMonthContext(monthInput) {
  const month = /^\d{4}-\d{2}$/.test(monthInput || '') ? monthInput : currentMonth();
  const [year, monthNumber] = month.split('-').map(Number);
  const lastDay = new Date(year, monthNumber, 0).getDate();

  return {
    month,
    startDate: `${month}-01`,
    endDate: `${month}-${pad(lastDay)}`,
  };
}

function parseId(value) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function normalizeText(value) {
  return String(value || '').trim();
}

function normalizeNullableText(value) {
  const normalized = normalizeText(value);
  return normalized ? normalized : null;
}

function roleLabel(role) {
  if (role === 'admin') return 'Admin';
  if (role === 'trainee') return 'Azubi';
  return 'Mitarbeiter';
}

function durationSql(alias) {
  return `(
    (CAST(substr(${alias}.end_time, 1, 2) AS INTEGER) * 60 + CAST(substr(${alias}.end_time, 4, 2) AS INTEGER))
    - (CAST(substr(${alias}.start_time, 1, 2) AS INTEGER) * 60 + CAST(substr(${alias}.start_time, 4, 2) AS INTEGER))
    - ${alias}.break_minutes
  )`;
}

function timeToMinutes(timeValue) {
  const [hours, minutes] = String(timeValue || '').split(':').map(Number);
  return hours * 60 + minutes;
}

function toMinutes(startTime, endTime, breakMinutes) {
  return timeToMinutes(endTime) - timeToMinutes(startTime) - Number(breakMinutes || 0);
}

function minutesToHoursString(minutes) {
  return (Number(minutes || 0) / 60).toFixed(2);
}

function isQuarterTime(timeValue) {
  return /^\d{2}:\d{2}$/.test(timeValue || '') && QUARTER_MINUTES.includes(timeValue.slice(3, 5));
}

function isValidDate(dateValue) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue || '')) return false;
  const [year, month, day] = dateValue.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}

function parseCookies(cookieHeader) {
  return String(cookieHeader || '')
    .split(';')
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .reduce((cookies, chunk) => {
      const separatorIndex = chunk.indexOf('=');
      if (separatorIndex === -1) return cookies;
      const key = decodeURIComponent(chunk.slice(0, separatorIndex).trim());
      const value = decodeURIComponent(chunk.slice(separatorIndex + 1).trim());
      cookies[key] = value;
      return cookies;
    }, {});
}

function safeRedirectTarget(target, fallback = '/dashboard') {
  if (!target || typeof target !== 'string') return fallback;
  if (!target.startsWith('/')) return fallback;
  if (target.startsWith('//')) return fallback;
  return target;
}

function slugify(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'export';
}

function buildQuery(params) {
  const searchParams = new URLSearchParams();
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    searchParams.set(key, String(value));
  });
  const queryString = searchParams.toString();
  return queryString ? `?${queryString}` : '';
}

function setFlash(req, type, message) {
  if (!req.session) return;
  if (!Array.isArray(req.session.flash)) req.session.flash = [];
  req.session.flash.push({ type, message });
}

function pullFlashes(req) {
  const flashes = Array.isArray(req.session?.flash) ? req.session.flash : [];
  if (req.session) req.session.flash = [];
  return flashes;
}

function ensureCsrfToken(req) {
  if (!req.session.csrf_token) {
    req.session.csrf_token = crypto.randomBytes(24).toString('hex');
  }
  return req.session.csrf_token;
}

function ensureSqlitePrerequisites(dbPath) {
  const directory = path.dirname(dbPath);
  if (!fs.existsSync(directory)) {
    fs.mkdirSync(directory, { recursive: true });
  }
  fs.accessSync(directory, fs.constants.R_OK | fs.constants.W_OK);
}

function getExistingTableSql(db, tableName) {
  return db.prepare(`
    SELECT sql
    FROM sqlite_master
    WHERE type = 'table' AND name = ?
  `).get(tableName)?.sql || null;
}

function hasRoleLiteral(sql, role) {
  return new RegExp(`['"]${role}['"]`, 'i').test(sql || '');
}

function hasTraineeRoleConstraint(sql) {
  return hasRoleLiteral(sql, 'trainee');
}

function hasDoubleQuotedRoleLiterals(sql) {
  return /"admin"|"employee"|"trainee"/i.test(sql || '');
}

function tableExists(sourceDb, tableName) {
  return !!sourceDb.prepare(`
    SELECT name
    FROM sqlite_master
    WHERE type = 'table' AND name = ?
  `).get(tableName);
}

function getTableColumns(sourceDb, tableName) {
  if (!tableExists(sourceDb, tableName)) return [];
  return sourceDb.prepare(`PRAGMA table_info(${tableName})`).all().map((column) => column.name);
}

function selectAs(columns, columnName, fallbackSql) {
  return columns.includes(columnName)
    ? `${columnName} AS ${columnName}`
    : `${fallbackSql} AS ${columnName}`;
}

function readTableRows(sourceDb, tableName, buildQuery) {
  if (!tableExists(sourceDb, tableName)) return [];
  const columns = getTableColumns(sourceDb, tableName);
  return sourceDb.prepare(buildQuery(columns)).all();
}

function syncSqliteSequence(db, tableName, rows) {
  const maxId = rows.reduce((max, row) => Math.max(max, Number(row.id || 0)), 0);
  if (!maxId) return;
  db.prepare('DELETE FROM sqlite_sequence WHERE name = ?').run(tableName);
  db.prepare('INSERT INTO sqlite_sequence(name, seq) VALUES (?, ?)').run(tableName, maxId);
}

function rebuildSqliteDatabase(dbPath) {
  const sourceDb = new Database(dbPath, { readonly: true });
  let exportData;

  try {
    exportData = {
      users: readTableRows(sourceDb, 'users', (columns) => `
        SELECT
          id,
          name,
          email,
          password_hash,
          role,
          ${selectAs(columns, 'active', '1')},
          ${selectAs(columns, 'created_at', "datetime('now')")}
        FROM users
        ORDER BY id
      `),
      projects: readTableRows(sourceDb, 'projects', (columns) => `
        SELECT
          id,
          name,
          ${selectAs(columns, 'active', '1')},
          ${selectAs(columns, 'created_at', "datetime('now')")}
        FROM projects
        ORDER BY id
      `),
      userProjects: readTableRows(sourceDb, 'user_projects', () => `
        SELECT user_id, project_id
        FROM user_projects
        ORDER BY user_id, project_id
      `),
      timeEntries: readTableRows(sourceDb, 'time_entries', (columns) => `
        SELECT
          id,
          user_id,
          project_id,
          work_date,
          start_time,
          end_time,
          ${selectAs(columns, 'break_minutes', '0')},
          ${selectAs(columns, 'notes', 'NULL')},
          ${selectAs(columns, 'created_by_user_id', 'user_id')},
          ${selectAs(columns, 'created_at', "datetime('now')")}
        FROM time_entries
        ORDER BY id
      `),
    };
  } finally {
    sourceDb.close();
  }

  const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '');
  const backupPath = `${dbPath}.bak-${stamp}`;
  const rebuiltPath = `${dbPath}.rebuilt-${stamp}`;
  fs.copyFileSync(dbPath, backupPath);

  const rebuiltDb = new Database(rebuiltPath);
  let rebuildError = null;

  try {
    rebuiltDb.pragma('foreign_keys = OFF');
    rebuiltDb.exec(CREATE_USERS_SQL);
    rebuiltDb.exec(CREATE_PROJECTS_SQL);
    rebuiltDb.exec(CREATE_USER_PROJECTS_SQL);
    rebuiltDb.exec(CREATE_TIME_ENTRIES_SQL);
    rebuiltDb.exec(CREATE_INDEX_SQL);

    const importData = rebuiltDb.transaction(() => {
      const insertUser = rebuiltDb.prepare(`
        INSERT INTO users (id, name, email, password_hash, role, active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      `);
      exportData.users.forEach((row) => {
        insertUser.run(row.id, row.name, row.email, row.password_hash, row.role, row.active, row.created_at);
      });

      const insertProject = rebuiltDb.prepare(`
        INSERT INTO projects (id, name, active, created_at)
        VALUES (?, ?, ?, ?)
      `);
      exportData.projects.forEach((row) => {
        insertProject.run(row.id, row.name, row.active, row.created_at);
      });

      const insertUserProject = rebuiltDb.prepare(`
        INSERT INTO user_projects (user_id, project_id)
        VALUES (?, ?)
      `);
      exportData.userProjects.forEach((row) => {
        insertUserProject.run(row.user_id, row.project_id);
      });

      const insertTimeEntry = rebuiltDb.prepare(`
        INSERT INTO time_entries (
          id, user_id, project_id, work_date, start_time, end_time,
          break_minutes, notes, created_by_user_id, created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `);
      exportData.timeEntries.forEach((row) => {
        insertTimeEntry.run(
          row.id,
          row.user_id,
          row.project_id,
          row.work_date,
          row.start_time,
          row.end_time,
          row.break_minutes,
          row.notes,
          row.created_by_user_id,
          row.created_at
        );
      });

      syncSqliteSequence(rebuiltDb, 'users', exportData.users);
      syncSqliteSequence(rebuiltDb, 'projects', exportData.projects);
      syncSqliteSequence(rebuiltDb, 'time_entries', exportData.timeEntries);
    });

    importData();
    rebuiltDb.pragma('foreign_keys = ON');
  } catch (error) {
    rebuildError = error;
  } finally {
    rebuiltDb.close();
  }

  if (rebuildError) {
    if (fs.existsSync(rebuiltPath)) {
      fs.unlinkSync(rebuiltPath);
    }
    throw rebuildError;
  }

  fs.copyFileSync(rebuiltPath, dbPath);
  fs.unlinkSync(rebuiltPath);
  console.warn(`Legacy SQLite schema repaired. Backup created at ${backupPath}`);
}

function ensureSqliteCompatibility(dbPath) {
  if (!fs.existsSync(dbPath)) return;

  const inspectionDb = new Database(dbPath, { readonly: true });
  let usersSql;

  try {
    usersSql = getExistingTableSql(inspectionDb, 'users');
  } finally {
    inspectionDb.close();
  }

  if (hasDoubleQuotedRoleLiterals(usersSql)) {
    rebuildSqliteDatabase(dbPath);
  }
}

function migrateUserRoleConstraint(db) {
  const usersSql = getExistingTableSql(db, 'users');

  if (!usersSql || hasTraineeRoleConstraint(usersSql)) {
    return;
  }

  db.pragma('foreign_keys = OFF');

  try {
    const recreate = db.transaction(() => {
      db.exec('ALTER TABLE user_projects RENAME TO user_projects_old;');
      db.exec('ALTER TABLE time_entries RENAME TO time_entries_old;');
      db.exec('ALTER TABLE users RENAME TO users_old;');

      db.exec(CREATE_USERS_SQL);
      db.exec(CREATE_PROJECTS_SQL);
      db.exec(CREATE_USER_PROJECTS_SQL);
      db.exec(CREATE_TIME_ENTRIES_SQL);
      db.exec(CREATE_INDEX_SQL);

      db.exec(`
        INSERT INTO users (id, name, email, password_hash, role, active, created_at)
        SELECT id, name, email, password_hash, role, active, created_at
        FROM users_old;
      `);
      db.exec(`
        INSERT INTO user_projects (user_id, project_id)
        SELECT user_id, project_id
        FROM user_projects_old;
      `);
      db.exec(`
        INSERT INTO time_entries (
          id, user_id, project_id, work_date, start_time, end_time,
          break_minutes, notes, created_by_user_id, created_at
        )
        SELECT
          id, user_id, project_id, work_date, start_time, end_time,
          break_minutes, notes, created_by_user_id, created_at
        FROM time_entries_old;
      `);

      db.exec('DROP TABLE users_old;');
      db.exec('DROP TABLE user_projects_old;');
      db.exec('DROP TABLE time_entries_old;');
    });

    recreate();
  } finally {
    db.pragma('foreign_keys = ON');
  }
}

function initializeDatabase(dbPath = DEFAULT_DB_PATH) {
  ensureSqlitePrerequisites(dbPath);
  ensureSqliteCompatibility(dbPath);

  const database = new Database(dbPath);
  database.pragma('foreign_keys = ON');
  database.exec(CREATE_USERS_SQL);
  database.exec(CREATE_PROJECTS_SQL);
  database.exec(CREATE_USER_PROJECTS_SQL);
  database.exec(CREATE_TIME_ENTRIES_SQL);
  database.exec(CREATE_INDEX_SQL);
  migrateUserRoleConstraint(database);

  return database;
}

function createStartupDiagnostics(error, dbPath) {
  const details = [
    `Datenbankpfad: ${dbPath}`,
    `Originalfehler: ${error.message}`,
  ];
  const message = String(error.message || '').toLowerCase();

  if (message.includes('permission') || message.includes('access') || message.includes('readonly')) {
    details.push('Pruefe Schreibrechte fuer das Verzeichnis der SQLite-Datei.');
  }
  if (message.includes('module') || message.includes('sqlite')) {
    details.push('Pruefe, ob die nativen SQLite-Abhaengigkeiten korrekt installiert wurden.');
  }
  if (message.includes('no such file') || message.includes('path')) {
    details.push('Pruefe, ob der konfigurierte Datenbankpfad existiert oder angelegt werden kann.');
  }

  return {
    heading: 'Startfehler',
    message: 'Die Anwendung konnte die Datenbank nicht initialisieren.',
    details,
  };
}

function getPreferredTheme(req) {
  if (req.session.theme === 'dark' || req.session.theme === 'light') {
    return req.session.theme;
  }

  const cookies = parseCookies(req.headers.cookie);
  if (cookies[THEME_COOKIE_NAME] === 'dark') return 'dark';
  return 'light';
}

function setTheme(res, req, theme) {
  req.session.theme = theme;
  res.cookie(THEME_COOKIE_NAME, theme, {
    httpOnly: true,
    sameSite: 'lax',
    secure: req.secure,
    path: '/',
    maxAge: 1000 * 60 * 60 * 24 * 365,
  });
}

function csvCell(value) {
  const stringValue = value === null || value === undefined ? '' : String(value);
  if (!/[;"\r\n]/.test(stringValue)) return stringValue;
  return `"${stringValue.replace(/"/g, '""')}"`;
}

function sendCsv(res, filename, columns, rows) {
  const lines = [
    columns.map((column) => csvCell(column)).join(';'),
    ...rows.map((row) => row.map((value) => csvCell(value)).join(';')),
  ];

  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
  res.send(`\uFEFF${lines.join('\r\n')}`);
}

function validateTimeEntry(db, currentUser, body, options = {}) {
  const isAdmin = currentUser.role === 'admin';
  const targetUserId = isAdmin ? parseId(body.user_id) : currentUser.id;
  const targetDate = isAdmin ? normalizeText(body.work_date) : todayIsoDate();
  const projectId = parseId(body.project_id);
  const startTime = normalizeText(body.start_time);
  const endTime = normalizeText(body.end_time);
  const breakMinutes = Math.max(0, Number.parseInt(body.break_minutes || '0', 10) || 0);
  const notes = normalizeNullableText(body.notes);
  const excludeEntryId = parseId(options.excludeEntryId);

  if (!targetUserId) return { error: 'Bitte einen gueltigen Benutzer auswaehlen.' };
  if (!projectId) return { error: 'Bitte ein gueltiges Projekt auswaehlen.' };
  if (!isValidDate(targetDate)) return { error: 'Bitte ein gueltiges Datum angeben.' };
  if (!isQuarterTime(startTime) || !isQuarterTime(endTime)) {
    return { error: 'Start- und Endzeit muessen auf Viertelstunden liegen.' };
  }
  if (timeToMinutes(endTime) <= timeToMinutes(startTime)) {
    return { error: 'Die Endzeit muss nach der Startzeit liegen.' };
  }

  const rawDuration = toMinutes(startTime, endTime, 0);
  if (breakMinutes >= rawDuration) {
    return { error: 'Die Pause muss kuerzer als die Einsatzdauer sein.' };
  }

  const targetUser = db.prepare(`
    SELECT id, name, email, role, active
    FROM users
    WHERE id = ? AND active = 1
  `).get(targetUserId);
  if (!targetUser) return { error: 'Der ausgewaehlte Benutzer ist nicht aktiv.' };

  const assignedProject = db.prepare(`
    SELECT p.id, p.name
    FROM projects p
    JOIN user_projects up ON up.project_id = p.id
    WHERE up.user_id = ? AND p.id = ? AND p.active = 1
  `).get(targetUserId, projectId);
  if (!assignedProject) return { error: 'Der Benutzer ist diesem Projekt nicht zugewiesen.' };

  const overlapParams = [targetUserId, targetDate, endTime, startTime];
  let overlapSql = `
    SELECT id
    FROM time_entries
    WHERE user_id = ?
      AND work_date = ?
      AND start_time < ?
      AND end_time > ?
  `;

  if (excludeEntryId) {
    overlapSql += ' AND id != ?';
    overlapParams.push(excludeEntryId);
  }

  const overlappingEntry = db.prepare(overlapSql).get(...overlapParams);
  if (overlappingEntry) {
    return { error: 'Die Zeitspanne ueberschneidet sich mit einer bestehenden Buchung.' };
  }

  return {
    value: {
      userId: targetUserId,
      workDate: targetDate,
      projectId,
      startTime,
      endTime,
      breakMinutes,
      notes,
      createdByUserId: currentUser.id,
    },
  };
}

function createApp({ db, startupError } = {}) {
  const app = express();

  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, '..', 'views'));
  app.locals.appName = APP_NAME;
  app.locals.hours = HOURS;
  app.locals.quarterMinutes = QUARTER_MINUTES;
  app.locals.roleLabel = roleLabel;
  app.locals.todayIsoDate = todayIsoDate;
  app.locals.minutesToHoursString = minutesToHoursString;
  app.locals.buildQuery = buildQuery;

  app.use(express.urlencoded({ extended: false }));
  app.use(express.static(path.join(__dirname, '..', 'public')));

  if (startupError) {
    app.use((req, res) => {
      res.status(500).render('error', {
        title: startupError.heading,
        heading: startupError.heading,
        message: startupError.message,
        details: startupError.details,
        backLink: null,
      });
    });
    return app;
  }

  app.use(session({
    name: SESSION_NAME,
    secret: process.env.SESSION_SECRET || 'dev-secret-change-me',
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: 'lax',
      secure: 'auto',
      maxAge: 1000 * 60 * 60 * 10,
    },
    store: new SQLiteStore({
      client: db,
      expired: {
        clear: true,
        intervalMs: 900000,
      },
    }),
  }));

  app.use((req, res, next) => {
    const sessionUserId = parseId(req.session.user_id);
    let currentUser = null;

    if (sessionUserId) {
      currentUser = db.prepare(`
        SELECT id, name, email, role, active
        FROM users
        WHERE id = ? AND active = 1
      `).get(sessionUserId) || null;

      if (!currentUser) {
        delete req.session.user_id;
      }
    }

    const theme = getPreferredTheme(req);
    req.currentUser = currentUser;
    req.theme = theme;
    req.session.theme = theme;

    res.locals.currentUser = currentUser ? { ...currentUser, role_label: roleLabel(currentUser.role) } : null;
    res.locals.theme = theme;
    res.locals.csrfToken = ensureCsrfToken(req);
    res.locals.flashes = pullFlashes(req);
    res.locals.currentUrl = req.originalUrl;
    res.locals.currentPath = req.path;
    res.locals.today = todayIsoDate();
    next();
  });

  app.use((req, res, next) => {
    const hasAdmin = db.prepare(`
      SELECT COUNT(*) AS count
      FROM users
      WHERE role = 'admin'
    `).get().count > 0;
    const setupPaths = new Set(['/setup', '/setup.php']);
    const loginPaths = new Set(['/login', '/login.php']);

    if (!hasAdmin && !setupPaths.has(req.path)) return res.redirect('/setup');
    if (hasAdmin && setupPaths.has(req.path)) return res.redirect('/login');
    if (!hasAdmin && loginPaths.has(req.path)) return res.redirect('/setup');
    return next();
  });

  function csrfRequired(req, res, next) {
    if ((req.body.csrf_token || '') !== req.session.csrf_token) {
      return res.status(400).render('error', {
        title: 'CSRF-Fehler',
        heading: 'CSRF-Fehler',
        message: 'Das Formular konnte nicht bestaetigt werden. Bitte die Seite neu laden.',
        details: [],
        backLink: req.currentUser ? '/dashboard' : '/login',
      });
    }
    return next();
  }

  function authRequired(req, res, next) {
    if (!req.currentUser) return res.redirect('/login');
    return next();
  }

  function adminRequired(req, res, next) {
    if (!req.currentUser || req.currentUser.role !== 'admin') {
      return res.status(403).render('error', {
        title: 'Keine Berechtigung',
        heading: 'Keine Berechtigung',
        message: 'Nur Admins haben Zugriff auf diesen Bereich.',
        details: [],
        backLink: req.currentUser ? '/dashboard' : '/login',
      });
    }
    return next();
  }

  function register(method, paths, ...handlers) {
    paths.forEach((routePath) => app[method](routePath, ...handlers));
  }

  register('get', ['/', '/index.php'], (req, res) => {
    if (req.currentUser) return res.redirect('/dashboard');
    return res.redirect('/login');
  });

  register('get', ['/setup', '/setup.php'], (req, res) => {
    res.render('setup', { title: 'Setup', error: null, form: { name: '', email: '' } });
  });

  register('post', ['/setup', '/setup.php'], (req, res) => {
    const hasAdmin = db.prepare(`
      SELECT COUNT(*) AS count
      FROM users
      WHERE role = 'admin'
    `).get().count > 0;

    if (hasAdmin) return res.redirect('/login');

    const name = normalizeText(req.body.name);
    const email = normalizeText(req.body.email).toLowerCase();
    const password = String(req.body.password || '');

    if (!name || !email || !password) {
      return res.status(400).render('setup', {
        title: 'Setup',
        error: 'Bitte alle Felder ausfuellen.',
        form: { name, email },
      });
    }
    if (password.length < 8) {
      return res.status(400).render('setup', {
        title: 'Setup',
        error: 'Das Passwort muss mindestens 8 Zeichen lang sein.',
        form: { name, email },
      });
    }

    try {
      const passwordHash = bcrypt.hashSync(password, 12);
      db.prepare(`
        INSERT INTO users (name, email, password_hash, role, active, created_at)
        VALUES (?, ?, ?, 'admin', 1, ?)
      `).run(name, email, passwordHash, new Date().toISOString());
      setFlash(req, 'success', 'Der erste Admin wurde angelegt. Bitte anmelden.');
      return res.redirect('/login');
    } catch (error) {
      return res.status(400).render('setup', {
        title: 'Setup',
        error: error.code === 'SQLITE_CONSTRAINT_UNIQUE'
          ? 'Die E-Mail-Adresse existiert bereits.'
          : 'Der Admin konnte nicht angelegt werden.',
        form: { name, email },
      });
    }
  });

  register('get', ['/login', '/login.php'], (req, res) => {
    if (req.currentUser) return res.redirect('/dashboard');
    return res.render('login', { title: 'Login', error: null, email: '' });
  });

  register('post', ['/login', '/login.php'], (req, res, next) => {
    const email = normalizeText(req.body.email).toLowerCase();
    const password = String(req.body.password || '');
    const user = db.prepare(`
      SELECT id, name, email, password_hash, role, active
      FROM users
      WHERE email = ?
    `).get(email);

    if (!user || !user.active || !bcrypt.compareSync(password, user.password_hash)) {
      return res.status(401).render('login', {
        title: 'Login',
        error: 'Ungueltige Zugangsdaten.',
        email,
      });
    }

    return req.session.regenerate((sessionError) => {
      if (sessionError) return next(sessionError);
      req.session.user_id = user.id;
      req.session.theme = getPreferredTheme(req);
      req.session.csrf_token = crypto.randomBytes(24).toString('hex');
      return res.redirect('/dashboard');
    });
  });

  function handleLogout(req, res) {
    req.session.destroy(() => {
      res.clearCookie(SESSION_NAME, { path: '/' });
      res.redirect('/login');
    });
  }

  register('get', ['/logout', '/logout.php'], handleLogout);
  register('post', ['/logout'], authRequired, csrfRequired, handleLogout);

  register('get', ['/theme_toggle', '/theme_toggle.php'], authRequired, (req, res) => {
    const theme = req.query.theme === 'dark' ? 'dark' : 'light';
    const returnTo = safeRedirectTarget(req.query.return_to, '/dashboard');
    setTheme(res, req, theme);
    return res.redirect(returnTo);
  });

  register('get', ['/dashboard', '/dashboard.php'], authRequired, (req, res) => {
    const monthContext = getMonthContext(req.query.month);
    const projects = db.prepare(`
      SELECT p.id, p.name
      FROM projects p
      JOIN user_projects up ON up.project_id = p.id
      WHERE up.user_id = ? AND p.active = 1
      ORDER BY LOWER(p.name), p.id
    `).all(req.currentUser.id);
    const entries = db.prepare(`
      SELECT
        t.id,
        t.work_date,
        t.start_time,
        t.end_time,
        t.break_minutes,
        t.notes,
        p.name AS project_name,
        c.name AS created_by_name,
        ${durationSql('t')} AS duration_minutes
      FROM time_entries t
      JOIN projects p ON p.id = t.project_id
      JOIN users c ON c.id = t.created_by_user_id
      WHERE t.user_id = ?
        AND t.work_date BETWEEN ? AND ?
      ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC
    `).all(req.currentUser.id, monthContext.startDate, monthContext.endDate).map((entry) => ({
      ...entry,
      duration_hours: minutesToHoursString(entry.duration_minutes),
    }));

    const totalMinutes = entries.reduce((sum, entry) => sum + Number(entry.duration_minutes || 0), 0);

    return res.render('dashboard', {
      title: 'Dashboard',
      month: monthContext.month,
      projects,
      entries,
      totalHours: minutesToHoursString(totalMinutes),
      form: {
        work_date: todayIsoDate(),
        start_time: '07:00',
        end_time: '15:00',
        break_minutes: 30,
        project_id: projects[0]?.id || '',
        notes: '',
      },
    });
  });

  register('post', ['/entries', '/time_entry_save.php'], authRequired, csrfRequired, (req, res) => {
    const validation = validateTimeEntry(db, req.currentUser, req.body);

    if (validation.error) {
      setFlash(req, 'error', validation.error);
      if (req.currentUser.role === 'admin') {
        return res.redirect(`/admin/time-entries${buildQuery({
          filter_month: normalizeText(req.body.work_date).slice(0, 7) || currentMonth(),
          filter_user_id: parseId(req.body.user_id),
          form_user_id: parseId(req.body.user_id),
        })}`);
      }
      return res.redirect(`/dashboard${buildQuery({ month: currentMonth() })}`);
    }

    const value = validation.value;
    db.prepare(`
      INSERT INTO time_entries (
        user_id, project_id, work_date, start_time, end_time,
        break_minutes, notes, created_by_user_id, created_at
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(
      value.userId,
      value.projectId,
      value.workDate,
      value.startTime,
      value.endTime,
      value.breakMinutes,
      value.notes,
      value.createdByUserId,
      new Date().toISOString()
    );

    setFlash(req, 'success', 'Die Zeitbuchung wurde gespeichert.');

    if (req.currentUser.role === 'admin') {
      return res.redirect(`/admin/time-entries${buildQuery({
        filter_month: value.workDate.slice(0, 7),
        filter_user_id: value.userId,
        form_user_id: value.userId,
      })}`);
    }

    return res.redirect(`/dashboard${buildQuery({ month: value.workDate.slice(0, 7) })}`);
  });

  register('get', ['/user_settings', '/user_settings.php'], authRequired, (req, res) => {
    res.render('user_settings', {
      title: 'Profil',
      user: req.currentUser,
    });
  });

  register('post', ['/user_change_password', '/user_change_password.php'], authRequired, csrfRequired, (req, res) => {
    const currentPassword = String(req.body.current_password || '');
    const newPassword = String(req.body.new_password || '');
    const confirmPassword = String(req.body.confirm_password || '');
    const user = db.prepare(`
      SELECT id, password_hash
      FROM users
      WHERE id = ?
    `).get(req.currentUser.id);

    if (!user || !bcrypt.compareSync(currentPassword, user.password_hash)) {
      setFlash(req, 'error', 'Das aktuelle Passwort ist nicht korrekt.');
      return res.redirect('/user_settings');
    }
    if (newPassword.length < 8) {
      setFlash(req, 'error', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.');
      return res.redirect('/user_settings');
    }
    if (newPassword !== confirmPassword) {
      setFlash(req, 'error', 'Die neue Passwortbestaetigung passt nicht.');
      return res.redirect('/user_settings');
    }

    db.prepare(`
      UPDATE users
      SET password_hash = ?
      WHERE id = ?
    `).run(bcrypt.hashSync(newPassword, 12), req.currentUser.id);

    setFlash(req, 'success', 'Das Passwort wurde aktualisiert.');
    return res.redirect('/user_settings');
  });

  register('get', ['/admin', '/admin.php'], authRequired, adminRequired, (req, res) => {
    res.render('admin', { title: 'Administration' });
  });

  register('get', ['/admin/users', '/admin_users.php'], authRequired, adminRequired, (req, res) => {
    const users = db.prepare(`
      SELECT id, name, email, role, active, created_at
      FROM users
      ORDER BY created_at DESC, id DESC
    `).all().map((user) => ({
      ...user,
      role_label: roleLabel(user.role),
    }));

    res.render('admin_users', {
      title: 'Benutzer',
      users,
      roles: ['admin', 'employee', 'trainee'],
    });
  });

  register('post', ['/admin/users/save', '/admin_save_user.php'], authRequired, adminRequired, csrfRequired, (req, res) => {
    const name = normalizeText(req.body.name);
    const email = normalizeText(req.body.email).toLowerCase();
    const password = String(req.body.password || '');
    const role = normalizeText(req.body.role);

    if (!name || !email || !password || !['admin', 'employee', 'trainee'].includes(role)) {
      setFlash(req, 'error', 'Bitte alle Benutzerdaten vollstaendig angeben.');
      return res.redirect('/admin/users');
    }
    if (password.length < 8) {
      setFlash(req, 'error', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
      return res.redirect('/admin/users');
    }

    try {
      db.prepare(`
        INSERT INTO users (name, email, password_hash, role, active, created_at)
        VALUES (?, ?, ?, ?, 1, ?)
      `).run(name, email, bcrypt.hashSync(password, 12), role, new Date().toISOString());
      setFlash(req, 'success', 'Der Benutzer wurde angelegt.');
    } catch (error) {
      setFlash(
        req,
        'error',
        error.code === 'SQLITE_CONSTRAINT_UNIQUE'
          ? 'Die E-Mail-Adresse ist bereits vergeben.'
          : 'Der Benutzer konnte nicht gespeichert werden.'
      );
    }

    return res.redirect('/admin/users');
  });

  register('post', ['/admin/users/update', '/admin_update_user.php'], authRequired, adminRequired, csrfRequired, (req, res) => {
    const userId = parseId(req.body.user_id);
    const name = normalizeText(req.body.name);
    const email = normalizeText(req.body.email).toLowerCase();
    const role = normalizeText(req.body.role);
    const password = String(req.body.password || '');

    if (!userId || !name || !email || !['admin', 'employee', 'trainee'].includes(role)) {
      setFlash(req, 'error', 'Bitte gueltige Benutzerdaten angeben.');
      return res.redirect('/admin/users');
    }
    if (req.currentUser.id === userId && role !== 'admin') {
      setFlash(req, 'error', 'Du kannst dir die Admin-Rolle nicht selbst entziehen.');
      return res.redirect('/admin/users');
    }
    if (password && password.length < 8) {
      setFlash(req, 'error', 'Ein neues Passwort muss mindestens 8 Zeichen lang sein.');
      return res.redirect('/admin/users');
    }

    try {
      const updateUser = db.transaction(() => {
        db.prepare(`
          UPDATE users
          SET name = ?, email = ?, role = ?
          WHERE id = ?
        `).run(name, email, role, userId);

        if (password) {
          db.prepare(`
            UPDATE users
            SET password_hash = ?
            WHERE id = ?
          `).run(bcrypt.hashSync(password, 12), userId);
        }
      });

      updateUser();
      setFlash(req, 'success', 'Der Benutzer wurde aktualisiert.');
    } catch (error) {
      setFlash(
        req,
        'error',
        error.code === 'SQLITE_CONSTRAINT_UNIQUE'
          ? 'Die E-Mail-Adresse ist bereits vergeben.'
          : 'Der Benutzer konnte nicht aktualisiert werden.'
      );
    }

    return res.redirect('/admin/users');
  });

  register('get', ['/admin/projects', '/admin_projects.php'], authRequired, adminRequired, (req, res) => {
    const filters = {
      name: normalizeText(req.query.name),
      created_from: normalizeText(req.query.created_from),
      created_to: normalizeText(req.query.created_to),
      sort: normalizeText(req.query.sort) || 'last_activity_desc',
    };
    const selectedUserId = parseId(req.query.user_id);
    const users = db.prepare(`
      SELECT id, name, email, role
      FROM users
      WHERE active = 1
      ORDER BY LOWER(name), id
    `).all();
    const selectedAssignmentUserId = selectedUserId || users[0]?.id || null;
    const sortSqlMap = {
      name_asc: 'LOWER(p.name) ASC',
      name_desc: 'LOWER(p.name) DESC',
      created_asc: 'p.created_at ASC',
      created_desc: 'p.created_at DESC',
      last_activity_asc: "COALESCE(MAX(t.work_date), '') ASC",
      last_activity_desc: "COALESCE(MAX(t.work_date), '') DESC",
    };
    const whereClauses = [];
    const params = [];

    if (filters.name) {
      whereClauses.push('LOWER(p.name) LIKE ?');
      params.push(`%${filters.name.toLowerCase()}%`);
    }
    if (isValidDate(filters.created_from)) {
      whereClauses.push('date(p.created_at) >= date(?)');
      params.push(filters.created_from);
    }
    if (isValidDate(filters.created_to)) {
      whereClauses.push('date(p.created_at) <= date(?)');
      params.push(filters.created_to);
    }

    const projects = db.prepare(`
      SELECT
        p.id,
        p.name,
        p.active,
        p.created_at,
        COUNT(DISTINCT up.user_id) AS assigned_user_count,
        MAX(t.work_date) AS last_activity
      FROM projects p
      LEFT JOIN user_projects up ON up.project_id = p.id
      LEFT JOIN time_entries t ON t.project_id = p.id
      ${whereClauses.length ? `WHERE ${whereClauses.join(' AND ')}` : ''}
      GROUP BY p.id
      ORDER BY ${sortSqlMap[filters.sort] || sortSqlMap.last_activity_desc}, p.id DESC
    `).all(...params);

    const assignmentRows = db.prepare(`
      SELECT user_id, project_id
      FROM user_projects
    `).all();
    const assignedProjectIds = new Set(
      assignmentRows
        .filter((row) => row.user_id === selectedAssignmentUserId)
        .map((row) => row.project_id)
    );

    res.render('admin_projects', {
      title: 'Projekte',
      users: users.map((user) => ({ ...user, role_label: roleLabel(user.role) })),
      selectedAssignmentUserId,
      assignedProjectIds,
      filters,
      sortOptions: [
        { value: 'last_activity_desc', label: 'Letzte Aktivitaet absteigend' },
        { value: 'last_activity_asc', label: 'Letzte Aktivitaet aufsteigend' },
        { value: 'name_asc', label: 'Name A-Z' },
        { value: 'name_desc', label: 'Name Z-A' },
        { value: 'created_desc', label: 'Neueste zuerst' },
        { value: 'created_asc', label: 'Aelteste zuerst' },
      ],
      projects,
    });
  });

  register('post', ['/admin/projects/save', '/admin_save_project.php'], authRequired, adminRequired, csrfRequired, (req, res) => {
    const projectId = parseId(req.body.project_id);
    const name = normalizeText(req.body.name);

    if (!name) {
      setFlash(req, 'error', 'Bitte einen Projektnamen angeben.');
      return res.redirect('/admin/projects');
    }

    try {
      if (projectId) {
        const result = db.prepare(`
          UPDATE projects
          SET name = ?
          WHERE id = ?
        `).run(name, projectId);

        if (!result.changes) {
          setFlash(req, 'error', 'Das Projekt wurde nicht gefunden.');
          return res.redirect('/admin/projects');
        }

        setFlash(req, 'success', 'Das Projekt wurde umbenannt.');
      } else {
        db.prepare(`
          INSERT INTO projects (name, active, created_at)
          VALUES (?, 1, ?)
        `).run(name, new Date().toISOString());
        setFlash(req, 'success', 'Das Projekt wurde angelegt.');
      }
    } catch (error) {
      setFlash(
        req,
        'error',
        error.code === 'SQLITE_CONSTRAINT_UNIQUE'
          ? 'Der Projektname ist bereits vergeben.'
          : 'Das Projekt konnte nicht gespeichert werden.'
      );
    }

    return res.redirect('/admin/projects');
  });

  register('post', ['/admin/assignments', '/admin_assign_projects.php'], authRequired, adminRequired, csrfRequired, (req, res) => {
    const userId = parseId(req.body.user_id);
    const projectIds = Array.isArray(req.body.project_ids)
      ? req.body.project_ids.map(parseId).filter(Boolean)
      : [parseId(req.body.project_ids)].filter(Boolean);

    if (!userId) {
      setFlash(req, 'error', 'Bitte einen gueltigen Benutzer auswaehlen.');
      return res.redirect('/admin/projects');
    }

    const replaceAssignments = db.transaction(() => {
      db.prepare('DELETE FROM user_projects WHERE user_id = ?').run(userId);
      const insertAssignment = db.prepare(`
        INSERT INTO user_projects (user_id, project_id)
        VALUES (?, ?)
      `);
      projectIds.forEach((projectId) => insertAssignment.run(userId, projectId));
    });

    replaceAssignments();
    setFlash(req, 'success', 'Die Projektzuweisungen wurden aktualisiert.');
    return res.redirect(`/admin/projects${buildQuery({ user_id: userId })}`);
  });

  register('get', ['/admin/time-entries', '/admin_time_entry.php'], authRequired, adminRequired, (req, res) => {
    const filterMonth = getMonthContext(req.query.filter_month);
    const filterUserId = parseId(req.query.filter_user_id);
    const entryId = parseId(req.query.entry_id);
    const activeUsers = db.prepare(`
      SELECT id, name, email, role
      FROM users
      WHERE active = 1
      ORDER BY LOWER(name), id
    `).all();
    const editEntry = entryId ? db.prepare(`
      SELECT
        id,
        user_id,
        project_id,
        work_date,
        start_time,
        end_time,
        break_minutes,
        notes
      FROM time_entries
      WHERE id = ?
    `).get(entryId) : null;
    const formUserId = editEntry?.user_id || parseId(req.query.form_user_id) || activeUsers[0]?.id || null;
    const formProjects = formUserId ? db.prepare(`
      SELECT p.id, p.name
      FROM projects p
      JOIN user_projects up ON up.project_id = p.id
      WHERE up.user_id = ? AND p.active = 1
      ORDER BY LOWER(p.name), p.id
    `).all(formUserId) : [];

    if (editEntry && editEntry.project_id && !formProjects.some((project) => project.id === editEntry.project_id)) {
      const currentProject = db.prepare(`
        SELECT id, name
        FROM projects
        WHERE id = ?
      `).get(editEntry.project_id);
      if (currentProject) formProjects.push(currentProject);
    }

    const reviewEntries = db.prepare(`
      SELECT
        t.id,
        t.user_id,
        t.project_id,
        t.work_date,
        t.start_time,
        t.end_time,
        t.break_minutes,
        t.notes,
        u.name AS user_name,
        p.name AS project_name,
        ${durationSql('t')} AS duration_minutes
      FROM time_entries t
      JOIN users u ON u.id = t.user_id
      JOIN projects p ON p.id = t.project_id
      WHERE t.work_date BETWEEN ? AND ?
        AND (? IS NULL OR t.user_id = ?)
      ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC
      LIMIT 200
    `).all(filterMonth.startDate, filterMonth.endDate, filterUserId, filterUserId).map((entry) => ({
      ...entry,
      duration_hours: minutesToHoursString(entry.duration_minutes),
    }));

    res.render('admin_time_entry', {
      title: 'Zeitbuchungen',
      activeUsers: activeUsers.map((user) => ({ ...user, role_label: roleLabel(user.role) })),
      formProjects,
      filterMonth: filterMonth.month,
      filterUserId,
      editEntry,
      formUserId,
      form: editEntry || {
        user_id: formUserId,
        project_id: formProjects[0]?.id || '',
        work_date: todayIsoDate(),
        start_time: '07:00',
        end_time: '15:00',
        break_minutes: 30,
        notes: '',
      },
      reviewEntries,
      reviewQuery: buildQuery({
        filter_month: filterMonth.month,
        filter_user_id: filterUserId,
        form_user_id: formUserId,
      }),
    });
  });

  register('post', ['/admin/time-entries/update', '/admin_update_time_entry.php'], authRequired, adminRequired, csrfRequired, (req, res) => {
    const entryId = parseId(req.body.entry_id);
    if (!entryId) {
      setFlash(req, 'error', 'Bitte eine gueltige Buchung auswaehlen.');
      return res.redirect('/admin/time-entries');
    }

    const existingEntry = db.prepare(`
      SELECT id
      FROM time_entries
      WHERE id = ?
    `).get(entryId);
    if (!existingEntry) {
      setFlash(req, 'error', 'Die Buchung wurde nicht gefunden.');
      return res.redirect('/admin/time-entries');
    }

    const validation = validateTimeEntry(db, req.currentUser, req.body, { excludeEntryId: entryId });
    if (validation.error) {
      setFlash(req, 'error', validation.error);
      return res.redirect(`/admin/time-entries${buildQuery({
        entry_id: entryId,
        filter_month: normalizeText(req.body.filter_month) || currentMonth(),
        filter_user_id: parseId(req.body.filter_user_id),
        form_user_id: parseId(req.body.user_id),
      })}`);
    }

    const value = validation.value;
    db.prepare(`
      UPDATE time_entries
      SET
        user_id = ?,
        project_id = ?,
        work_date = ?,
        start_time = ?,
        end_time = ?,
        break_minutes = ?,
        notes = ?,
        created_by_user_id = ?
      WHERE id = ?
    `).run(
      value.userId,
      value.projectId,
      value.workDate,
      value.startTime,
      value.endTime,
      value.breakMinutes,
      value.notes,
      req.currentUser.id,
      entryId
    );

    setFlash(req, 'success', 'Die Zeitbuchung wurde aktualisiert.');
    return res.redirect(`/admin/time-entries${buildQuery({
      filter_month: value.workDate.slice(0, 7),
      filter_user_id: value.userId,
      form_user_id: value.userId,
    })}`);
  });

  register('get', ['/admin/totals', '/admin_totals.php'], authRequired, adminRequired, (req, res) => {
    const monthContext = getMonthContext(req.query.month);
    const totalRows = db.prepare(`
      SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        COALESCE(SUM(${durationSql('t')}), 0) AS total_minutes
      FROM users u
      LEFT JOIN time_entries t
        ON t.user_id = u.id
        AND t.work_date BETWEEN ? AND ?
      WHERE u.active = 1
      GROUP BY u.id
      ORDER BY LOWER(u.name), u.id
    `).all(monthContext.startDate, monthContext.endDate).map((row) => ({
      ...row,
      role_label: roleLabel(row.role),
      total_hours: minutesToHoursString(row.total_minutes),
    }));

    if (req.query.export === 'csv') {
      return sendCsv(
        res,
        `monthly-totals-${monthContext.month}.csv`,
        ['Name', 'E-Mail', 'Rolle', 'Stunden'],
        totalRows.map((row) => [row.name, row.email, row.role_label, row.total_hours])
      );
    }

    if (req.query.export === 'user_csv') {
      const userId = parseId(req.query.user_id);
      const user = userId ? db.prepare(`
        SELECT id, name, email, role
        FROM users
        WHERE id = ?
      `).get(userId) : null;

      if (!user) {
        setFlash(req, 'error', 'Der Benutzer wurde nicht gefunden.');
        return res.redirect(`/admin/totals${buildQuery({ month: monthContext.month })}`);
      }

      const detailRows = db.prepare(`
        SELECT
          t.work_date,
          p.name AS project_name,
          t.start_time,
          t.end_time,
          t.break_minutes,
          t.notes,
          c.name AS created_by_name,
          ${durationSql('t')} AS duration_minutes
        FROM time_entries t
        JOIN projects p ON p.id = t.project_id
        JOIN users c ON c.id = t.created_by_user_id
        WHERE t.user_id = ?
          AND t.work_date BETWEEN ? AND ?
        ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC
      `).all(user.id, monthContext.startDate, monthContext.endDate);

      return sendCsv(
        res,
        `${slugify(user.name)}-${monthContext.month}.csv`,
        ['Datum', 'Projekt', 'Start', 'Ende', 'Pause', 'Notiz', 'Erfasst von', 'Stunden'],
        detailRows.map((row) => [
          row.work_date,
          row.project_name,
          row.start_time,
          row.end_time,
          row.break_minutes,
          row.notes || '',
          row.created_by_name,
          minutesToHoursString(row.duration_minutes),
        ])
      );
    }

    return res.render('admin_totals', {
      title: 'Monatssummen',
      month: monthContext.month,
      totalRows,
    });
  });

  register('get', ['/admin/employee-details', '/admin_employee_details.php'], authRequired, adminRequired, (req, res) => {
    const userId = parseId(req.query.user_id);
    const monthContext = getMonthContext(req.query.month);
    const user = userId ? db.prepare(`
      SELECT id, name, email, role
      FROM users
      WHERE id = ?
    `).get(userId) : null;

    if (!user) {
      setFlash(req, 'error', 'Der Benutzer wurde nicht gefunden.');
      return res.redirect('/admin/totals');
    }

    const entries = db.prepare(`
      SELECT
        t.work_date,
        p.name AS project_name,
        t.start_time,
        t.end_time,
        t.break_minutes,
        t.notes,
        c.name AS created_by_name,
        ${durationSql('t')} AS duration_minutes
      FROM time_entries t
      JOIN projects p ON p.id = t.project_id
      JOIN users c ON c.id = t.created_by_user_id
      WHERE t.user_id = ?
        AND t.work_date BETWEEN ? AND ?
      ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC
    `).all(user.id, monthContext.startDate, monthContext.endDate).map((entry) => ({
      ...entry,
      duration_hours: minutesToHoursString(entry.duration_minutes),
    }));

    const totalMinutes = entries.reduce((sum, entry) => sum + Number(entry.duration_minutes || 0), 0);

    return res.render('admin_employee_details', {
      title: 'Benutzerdetails',
      month: monthContext.month,
      user: { ...user, role_label: roleLabel(user.role) },
      entries,
      totalHours: minutesToHoursString(totalMinutes),
    });
  });

  register('get', ['/admin/project-details', '/admin_project_details.php'], authRequired, adminRequired, (req, res) => {
    const projectId = parseId(req.query.project_id);
    const monthContext = getMonthContext(req.query.month);
    const project = projectId ? db.prepare(`
      SELECT id, name, active, created_at
      FROM projects
      WHERE id = ?
    `).get(projectId) : null;

    if (!project) {
      setFlash(req, 'error', 'Das Projekt wurde nicht gefunden.');
      return res.redirect('/admin/projects');
    }

    const entries = db.prepare(`
      SELECT
        t.work_date,
        u.name AS user_name,
        t.start_time,
        t.end_time,
        t.break_minutes,
        t.notes,
        c.name AS created_by_name,
        ${durationSql('t')} AS duration_minutes
      FROM time_entries t
      JOIN users u ON u.id = t.user_id
      JOIN users c ON c.id = t.created_by_user_id
      WHERE t.project_id = ?
        AND t.work_date BETWEEN ? AND ?
      ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC
    `).all(project.id, monthContext.startDate, monthContext.endDate).map((entry) => ({
      ...entry,
      duration_hours: minutesToHoursString(entry.duration_minutes),
    }));

    const totalMinutes = entries.reduce((sum, entry) => sum + Number(entry.duration_minutes || 0), 0);

    if (req.query.export === 'csv') {
      return sendCsv(
        res,
        `${slugify(project.name)}-${monthContext.month}.csv`,
        ['Datum', 'Mitarbeiter', 'Start', 'Ende', 'Pause', 'Notiz', 'Erfasst von', 'Stunden'],
        entries.map((entry) => [
          entry.work_date,
          entry.user_name,
          entry.start_time,
          entry.end_time,
          entry.break_minutes,
          entry.notes || '',
          entry.created_by_name,
          entry.duration_hours,
        ])
      );
    }

    return res.render('admin_project_details', {
      title: 'Projektdetails',
      month: monthContext.month,
      project,
      entries,
      totalHours: minutesToHoursString(totalMinutes),
      backQuery: buildQuery({
        name: normalizeText(req.query.name),
        created_from: normalizeText(req.query.created_from),
        created_to: normalizeText(req.query.created_to),
        sort: normalizeText(req.query.sort),
        user_id: parseId(req.query.user_id),
      }),
    });
  });

  register('get', ['/report', '/report.php'], authRequired, (req, res) => {
    setFlash(req, 'success', 'Der alte Monatsreport wurde entfernt. Bitte nutze das Dashboard oder die Admin-Auswertungen.');
    return res.redirect('/dashboard');
  });

  app.use((req, res) => {
    res.status(404).render('error', {
      title: 'Nicht gefunden',
      heading: 'Nicht gefunden',
      message: 'Die angeforderte Seite existiert nicht.',
      details: [],
      backLink: req.currentUser ? '/dashboard' : '/login',
    });
  });

  app.use((error, req, res, next) => {
    if (res.headersSent) return next(error);
    console.error(error);
    return res.status(500).render('error', {
      title: 'Serverfehler',
      heading: 'Serverfehler',
      message: 'Es ist ein unerwarteter Serverfehler aufgetreten.',
      details: [String(error.message || error)],
      backLink: req.currentUser ? '/dashboard' : '/login',
    });
  });

  return app;
}

let database = null;
let startupError = null;

try {
  database = initializeDatabase();
} catch (error) {
  startupError = createStartupDiagnostics(error, DEFAULT_DB_PATH);
}

const app = createApp({ db: database, startupError });

function startServer(port = Number(process.env.PORT || 3000)) {
  return app.listen(port, () => {
    console.log(`Server laeuft auf http://localhost:${port}`);
  });
}

if (require.main === module) {
  startServer();
}

module.exports = {
  app,
  createApp,
  initializeDatabase,
  startServer,
};
