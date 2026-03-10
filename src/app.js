const express = require('express');
const path = require('path');
const session = require('express-session');
const SQLiteStore = require('better-sqlite3-session-store')(session);
const bcrypt = require('bcryptjs');
const Database = require('better-sqlite3');

const app = express();
const dbPath = process.env.DB_PATH || path.join(__dirname, '..', 'data', 'stundenerfassung.sqlite');
const db = new Database(dbPath);
db.pragma('foreign_keys = ON');

function bootstrapSchema() {
  db.exec(`
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      email TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      role TEXT NOT NULL CHECK(role IN ('admin', 'employee', 'trainee')),
      active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS projects (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL UNIQUE,
      active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS user_projects (
      user_id INTEGER NOT NULL,
      project_id INTEGER NOT NULL,
      PRIMARY KEY (user_id, project_id),
      FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    );

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

    CREATE INDEX IF NOT EXISTS idx_time_entries_user_date ON time_entries(user_id, work_date);
  `);
}

bootstrapSchema();

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, '..', 'views'));
app.use(express.urlencoded({ extended: false }));
app.use(express.static(path.join(__dirname, '..', 'public')));

app.use(
  session({
    secret: process.env.SESSION_SECRET || 'dev-secret-change-me',
    resave: false,
    saveUninitialized: false,
    cookie: { maxAge: 1000 * 60 * 60 * 10 },
    store: new SQLiteStore({
      client: db,
      expired: {
        clear: true,
        intervalMs: 900000,
      },
    }),
  })
);

app.use((req, res, next) => {
  res.locals.currentUser = req.session.user || null;
  next();
});

function authRequired(req, res, next) {
  if (!req.session.user) return res.redirect('/login');
  return next();
}

function adminRequired(req, res, next) {
  if (!req.session.user || req.session.user.role !== 'admin') return res.status(403).render('error', { message: 'Nur Admins haben Zugriff.' });
  return next();
}

function getMonthRange(monthInput) {
  const month = monthInput || new Date().toISOString().slice(0, 7);
  return month;
}

function toMinutes(start, end, breakMinutes) {
  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const total = (eh * 60 + em) - (sh * 60 + sm) - Number(breakMinutes || 0);
  return Math.max(total, 0);
}

app.get('/', (req, res) => {
  if (!req.session.user) return res.redirect('/login');
  return res.redirect('/dashboard');
});

app.get('/setup', (req, res) => {
  const userCount = db.prepare('SELECT COUNT(*) AS count FROM users').get().count;
  if (userCount > 0) return res.redirect('/login');
  return res.render('setup', { error: null });
});

app.post('/setup', (req, res) => {
  const userCount = db.prepare('SELECT COUNT(*) AS count FROM users').get().count;
  if (userCount > 0) return res.redirect('/login');

  const { name, email, password } = req.body;
  if (!name || !email || !password) return res.status(400).render('setup', { error: 'Bitte alle Felder ausfüllen.' });

  const hash = bcrypt.hashSync(password, 10);
  db.prepare('INSERT INTO users(name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
    .run(name.trim(), email.trim().toLowerCase(), hash, 'admin', new Date().toISOString());

  return res.redirect('/login');
});

app.get('/login', (req, res) => {
  if (req.session.user) return res.redirect('/dashboard');
  return res.render('login', { error: null });
});

app.post('/login', (req, res) => {
  const email = (req.body.email || '').trim().toLowerCase();
  const password = req.body.password || '';
  const user = db.prepare('SELECT id, name, email, password_hash, role, active FROM users WHERE email = ?').get(email);

  if (!user || !user.active || !bcrypt.compareSync(password, user.password_hash)) {
    return res.status(401).render('login', { error: 'Ungültige Zugangsdaten.' });
  }

  req.session.user = { id: user.id, name: user.name, email: user.email, role: user.role };
  return res.redirect('/dashboard');
});

app.post('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/login'));
});

app.get('/dashboard', authRequired, (req, res) => {
  const month = getMonthRange(req.query.month);
  const dateStart = `${month}-01`;
  const dateEnd = `${month}-31`;

  const projects = db.prepare(`
    SELECT p.id, p.name
    FROM projects p
    JOIN user_projects up ON up.project_id = p.id
    WHERE up.user_id = ? AND p.active = 1
    ORDER BY p.name
  `).all(req.session.user.id);

  const entries = db.prepare(`
    SELECT t.*, p.name AS project_name, u.name AS employee_name, c.name AS created_by_name
    FROM time_entries t
    JOIN projects p ON p.id = t.project_id
    JOIN users u ON u.id = t.user_id
    JOIN users c ON c.id = t.created_by_user_id
    WHERE t.user_id = ?
      AND t.work_date BETWEEN ? AND ?
    ORDER BY t.work_date DESC, t.start_time DESC
  `).all(req.session.user.id, dateStart, dateEnd);

  const totalMinutes = entries.reduce((sum, e) => sum + toMinutes(e.start_time, e.end_time, e.break_minutes), 0);
  const totalHours = (totalMinutes / 60).toFixed(2);

  return res.render('dashboard', { month, projects, entries, totalHours, isAdmin: req.session.user.role === 'admin' });
});

app.post('/entries', authRequired, (req, res) => {
  const { project_id, work_date, start_time, end_time, break_minutes, notes } = req.body;

  const assigned = db.prepare('SELECT 1 FROM user_projects WHERE user_id = ? AND project_id = ?').get(req.session.user.id, Number(project_id));
  if (!assigned && req.session.user.role !== 'admin') return res.status(403).render('error', { message: 'Projekt nicht zugewiesen.' });

  db.prepare(`
    INSERT INTO time_entries(user_id, project_id, work_date, start_time, end_time, break_minutes, notes, created_by_user_id, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    req.session.user.id,
    Number(project_id),
    work_date,
    start_time,
    end_time,
    Number(break_minutes || 0),
    notes || null,
    req.session.user.id,
    new Date().toISOString()
  );

  return res.redirect('/dashboard');
});

app.get('/admin', authRequired, adminRequired, (req, res) => {
  const users = db.prepare('SELECT id, name, email, role, active FROM users ORDER BY created_at DESC').all();
  const projects = db.prepare('SELECT id, name, active FROM projects ORDER BY name').all();
  const assignments = db.prepare('SELECT user_id, project_id FROM user_projects').all();

  const assignmentMap = new Map();
  assignments.forEach((a) => {
    if (!assignmentMap.has(a.user_id)) assignmentMap.set(a.user_id, new Set());
    assignmentMap.get(a.user_id).add(a.project_id);
  });

  const report = db.prepare(`
    SELECT u.name AS user_name,
           SUM(((CAST(substr(t.end_time,1,2) AS INTEGER) * 60 + CAST(substr(t.end_time,4,2) AS INTEGER))
             - (CAST(substr(t.start_time,1,2) AS INTEGER) * 60 + CAST(substr(t.start_time,4,2) AS INTEGER))
             - t.break_minutes)) AS total_minutes
    FROM users u
    LEFT JOIN time_entries t ON t.user_id = u.id
    GROUP BY u.id
    ORDER BY u.name
  `).all().map((row) => ({ ...row, total_hours: ((row.total_minutes || 0) / 60).toFixed(2) }));

  return res.render('admin', { users, projects, assignmentMap, report });
});

app.post('/admin/users', authRequired, adminRequired, (req, res) => {
  const { name, email, password, role } = req.body;
  if (!name || !email || !password || !role) return res.status(400).render('error', { message: 'Unvollständige Benutzerdaten.' });

  const hash = bcrypt.hashSync(password, 10);
  db.prepare('INSERT INTO users(name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
    .run(name.trim(), email.trim().toLowerCase(), hash, role, new Date().toISOString());
  return res.redirect('/admin');
});

app.post('/admin/projects', authRequired, adminRequired, (req, res) => {
  const { name } = req.body;
  if (!name) return res.status(400).render('error', { message: 'Projektname fehlt.' });
  db.prepare('INSERT INTO projects(name, active, created_at) VALUES (?, 1, ?)').run(name.trim(), new Date().toISOString());
  return res.redirect('/admin');
});

app.post('/admin/assignments', authRequired, adminRequired, (req, res) => {
  const userId = Number(req.body.user_id);
  const projectIds = Array.isArray(req.body.project_ids) ? req.body.project_ids.map(Number) : (req.body.project_ids ? [Number(req.body.project_ids)] : []);

  const tx = db.transaction(() => {
    db.prepare('DELETE FROM user_projects WHERE user_id = ?').run(userId);
    const stmt = db.prepare('INSERT INTO user_projects(user_id, project_id) VALUES (?, ?)');
    projectIds.forEach((pid) => stmt.run(userId, pid));
  });
  tx();

  return res.redirect('/admin');
});

app.get('/report', authRequired, (req, res) => {
  const month = getMonthRange(req.query.month);
  const dateStart = `${month}-01`;
  const dateEnd = `${month}-31`;

  const rows = db.prepare(`
    SELECT t.work_date, u.name AS employee, p.name AS project,
           t.start_time, t.end_time, t.break_minutes, t.notes
    FROM time_entries t
    JOIN users u ON u.id = t.user_id
    JOIN projects p ON p.id = t.project_id
    WHERE t.work_date BETWEEN ? AND ?
      AND (? = 1 OR t.user_id = ?)
    ORDER BY t.work_date DESC
  `).all(dateStart, dateEnd, req.session.user.role === 'admin' ? 1 : 0, req.session.user.id)
    .map((row) => ({
      ...row,
      duration_hours: (toMinutes(row.start_time, row.end_time, row.break_minutes) / 60).toFixed(2),
    }));

  return res.render('report', { month, rows, isAdmin: req.session.user.role === 'admin' });
});

app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).render('error', { message: 'Unerwarteter Serverfehler.' });
});

const port = process.env.PORT || 3000;
app.listen(port, () => {
  console.log(`Server läuft auf http://localhost:${port}`);
});
