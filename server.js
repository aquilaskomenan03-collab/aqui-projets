'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');

const root = __dirname;
const port = Number(process.env.PORT || 3000);
const adminPassword = process.env.MG_ADMIN_PASSWORD || '';
if (adminPassword.length < 12) {
  console.error('Configure MG_ADMIN_PASSWORD avec au moins 12 caractères avant de démarrer.');
  process.exit(1);
}
const dataDir = path.join(root, 'data');
fs.mkdirSync(dataDir, { recursive: true });
const db = new DatabaseSync(path.join(dataDir, 'mg-multiservice.sqlite'));
db.exec(`
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;
  CREATE TABLE IF NOT EXISTS quote_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT,
    service TEXT NOT NULL,
    message TEXT NOT NULL,
    consent INTEGER NOT NULL CHECK(consent = 1),
    status TEXT NOT NULL DEFAULT 'Nouvelle',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS portfolio_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    title TEXT NOT NULL,
    filename TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS site_info (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
  );
`);
const leadColumns = db.prepare('PRAGMA table_info(quote_requests)').all().map(column => column.name);
if (!leadColumns.includes('status')) db.exec("ALTER TABLE quote_requests ADD COLUMN status TEXT NOT NULL DEFAULT 'Nouvelle'");
const uploadDir = path.join(root, 'assets', 'uploads');
fs.mkdirSync(uploadDir, { recursive: true });
const insertLead = db.prepare('INSERT INTO quote_requests (name, phone, email, service, message, consent) VALUES (?, ?, ?, ?, ?, 1)');
const listLeads = db.prepare('SELECT id, name, phone, email, service, message, status, created_at FROM quote_requests ORDER BY id DESC LIMIT 300');
const listPhotos = db.prepare('SELECT id, category, title, filename, created_at FROM portfolio_photos ORDER BY id DESC');
const listSiteInfo = db.prepare('SELECT key, value FROM site_info');
const mimeTypes = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const requestCounts = new Map();
const loginAttempts = new Map();
const adminSessions = new Map();
function send(res, status, body, type = 'application/json; charset=utf-8', extra = {}) {
  res.writeHead(status, { 'Content-Type': type, 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store', ...extra });
  res.end(type.startsWith('application/json') ? JSON.stringify(body) : body);
}
function authorized(req) {
  const cookie = String(req.headers.cookie || '').split(';').map(value => value.trim()).find(value => value.startsWith('mg_admin_session='));
  if (!cookie) return false;
  const token = cookie.slice('mg_admin_session='.length);
  const expires = adminSessions.get(token);
  if (!expires) return false;
  if (expires <= Date.now()) { adminSessions.delete(token); return false; }
  return true;
}
function requireAdmin(req, res) {
  if (authorized(req)) return true;
  send(res, 401, { error: 'Connecte-toi pour accéder à cet espace.' });
  return false;
}
function readJson(req, limit = 12000) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > limit) { reject(new Error('payload_too_large')); req.destroy(); }
    });
    req.on('end', () => {
      try { resolve(JSON.parse(body)); } catch { reject(new Error('invalid_json')); }
    });
    req.on('error', reject);
  });
}
function cleanText(value, max) { return typeof value === 'string' ? value.trim().slice(0, max) : ''; }
const allowedServices = new Set(['Menuiserie', 'Peinture', 'Vernissage', 'Autre demande']);
const leadStatuses = new Set(['Nouvelle', 'Contactée', 'En cours', 'Terminée']);
const infoLimits = { serviceHours: 160, serviceArea: 240, responseTime: 160, warranty: 240 };
function siteInfoObject() {
  return Object.fromEntries(Object.entries({ serviceHours: '', serviceArea: 'Abidjan, Côte d’Ivoire', responseTime: '', warranty: '' }).map(([key, fallback]) => [key, fallback]));
}
function currentSiteInfo() {
  const values = siteInfoObject();
  for (const row of listSiteInfo.all()) if (Object.hasOwn(values, row.key)) values[row.key] = row.value;
  return values;
}
const server = http.createServer(async (req, res) => {
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (url.pathname === '/api/session' && req.method === 'GET') return send(res, 200, { authenticated: authorized(req) });
  if (url.pathname === '/api/login' && req.method === 'POST') {
    const ip = req.socket.remoteAddress || 'unknown';
    const now = Date.now();
    const recent = (loginAttempts.get(ip) || []).filter(time => now - time < 60_000);
    if (recent.length >= 8) return send(res, 429, { error: 'Trop d’essais. Réessaie dans une minute.' });
    recent.push(now); loginAttempts.set(ip, recent);
    try {
      const data = await readJson(req, 3000);
      const supplied = typeof data.password === 'string' ? data.password : '';
      const expectedBuffer = Buffer.from(adminPassword);
      const suppliedBuffer = Buffer.from(supplied);
      const passOk = expectedBuffer.length === suppliedBuffer.length && crypto.timingSafeEqual(expectedBuffer, suppliedBuffer);
      if (!passOk) return send(res, 401, { error: 'Mot de passe incorrect.' });
      const token = crypto.randomBytes(32).toString('hex');
      adminSessions.set(token, now + 8 * 60 * 60 * 1000);
      const secure = req.socket.encrypted || req.headers['x-forwarded-proto'] === 'https' ? '; Secure' : '';
      return send(res, 200, { ok: true }, 'application/json; charset=utf-8', { 'Set-Cookie': `mg_admin_session=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800${secure}` });
    } catch (error) {
      return send(res, error.message === 'invalid_json' ? 400 : 413, { error: 'Connexion impossible.' });
    }
  }
  if (url.pathname === '/api/logout' && req.method === 'POST') {
    const cookie = String(req.headers.cookie || '').split(';').map(value => value.trim()).find(value => value.startsWith('mg_admin_session='));
    if (cookie) adminSessions.delete(cookie.slice('mg_admin_session='.length));
    return send(res, 200, { ok: true }, 'application/json; charset=utf-8', { 'Set-Cookie': 'mg_admin_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0' });
  }
  if (url.pathname === '/api/photos' && req.method === 'GET') return send(res, 200, listPhotos.all().map(photo => ({ ...photo, url: `/assets/uploads/${photo.filename}` })));
  if (url.pathname === '/api/site-info' && req.method === 'GET') return send(res, 200, currentSiteInfo());
  if (url.pathname === '/api/admin/photos' && req.method === 'POST') {
    if (!requireAdmin(req, res)) return;
    if (!String(req.headers['content-type'] || '').includes('application/json')) return send(res, 415, { error: 'Format de photo non pris en charge.' });
    try {
      const data = await readJson(req, 7_500_000);
      const category = cleanText(data.category, 30);
      const title = cleanText(data.title, 90);
      const match = typeof data.image === 'string' && data.image.match(/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$/);
      if (!['Menuiserie', 'Peinture', 'Vernissage'].includes(category) || !title || !match) return send(res, 400, { error: 'Vérifiez le métier, le titre et le format de la photo.' });
      const buffer = Buffer.from(match[2], 'base64');
      if (!buffer.length || buffer.length > 5 * 1024 * 1024) return send(res, 413, { error: 'La photo doit faire moins de 5 Mo.' });
      const ext = match[1] === 'jpeg' ? '.jpg' : `.${match[1]}`;
      const filename = `${crypto.randomUUID()}${ext}`;
      fs.writeFileSync(path.join(uploadDir, filename), buffer, { flag: 'wx' });
      db.prepare('INSERT INTO portfolio_photos (category, title, filename) VALUES (?, ?, ?)').run(category, title, filename);
      return send(res, 201, { ok: true });
    } catch (error) {
      if (error.message === 'payload_too_large') return send(res, 413, { error: 'La photo est trop volumineuse.' });
      if (error.message === 'invalid_json') return send(res, 400, { error: 'La photo est illisible.' });
      console.error('Erreur pendant l’ajout de la photo:', error.message);
      return send(res, 500, { error: 'La photo n’a pas pu être enregistrée.' });
    }
  }
  const photoDeleteMatch = url.pathname.match(/^\/api\/admin\/photos\/(\d+)$/);
  if (photoDeleteMatch && req.method === 'DELETE') {
    if (!requireAdmin(req, res)) return;
    const photo = db.prepare('SELECT filename FROM portfolio_photos WHERE id = ?').get(Number(photoDeleteMatch[1]));
    if (!photo) return send(res, 404, { error: 'Photo introuvable.' });
    db.prepare('DELETE FROM portfolio_photos WHERE id = ?').run(Number(photoDeleteMatch[1]));
    if (path.basename(photo.filename) === photo.filename) fs.rm(path.join(uploadDir, photo.filename), () => {});
    return send(res, 200, { ok: true });
  }
  if (url.pathname === '/api/admin/site-info' && req.method === 'PATCH') {
    if (!requireAdmin(req, res)) return;
    try {
      const data = await readJson(req, 5000);
      const upsert = db.prepare('INSERT INTO site_info (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
      for (const [key, max] of Object.entries(infoLimits)) if (Object.hasOwn(data, key)) upsert.run(key, cleanText(data[key], max));
      return send(res, 200, currentSiteInfo());
    } catch (error) {
      return send(res, error.message === 'invalid_json' ? 400 : 413, { error: 'Les informations n’ont pas pu être enregistrées.' });
    }
  }
  const leadStatusMatch = url.pathname.match(/^\/api\/leads\/(\d+)$/);
  if (leadStatusMatch && req.method === 'PATCH') {
    if (!requireAdmin(req, res)) return;
    try {
      const data = await readJson(req, 2000);
      if (!leadStatuses.has(data.status)) return send(res, 400, { error: 'Statut inconnu.' });
      const result = db.prepare('UPDATE quote_requests SET status = ? WHERE id = ?').run(data.status, Number(leadStatusMatch[1]));
      if (!result.changes) return send(res, 404, { error: 'Demande introuvable.' });
      return send(res, 200, { ok: true });
    } catch { return send(res, 400, { error: 'Mise à jour impossible.' }); }
  }
  if (url.pathname === '/api/leads' && req.method === 'POST') {
    const ip = req.socket.remoteAddress || 'unknown';
    const now = Date.now();
    const recent = (requestCounts.get(ip) || []).filter(time => now - time < 60_000);
    if (recent.length >= 8) return send(res, 429, { error: 'Trop de demandes. Réessayez dans une minute.' });
    recent.push(now); requestCounts.set(ip, recent);
    if (!String(req.headers['content-type'] || '').includes('application/json')) return send(res, 415, { error: 'Format de demande non pris en charge.' });
    try {
      const data = await readJson(req);
      const name = cleanText(data.name, 100);
      const phone = cleanText(data.phone, 30);
      const email = cleanText(data.email, 254);
      const service = cleanText(data.service, 40);
      const message = cleanText(data.message, 2000);
      if (!name || !phone || !message || !allowedServices.has(service) || data.consent !== 'on') return send(res, 400, { error: 'Vérifiez les champs obligatoires et votre consentement.' });
      if (!/^[+\d() .-]{7,30}$/.test(phone)) return send(res, 400, { error: 'Le numéro de téléphone semble invalide.' });
      if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return send(res, 400, { error: 'L’adresse e-mail semble invalide.' });
      insertLead.run(name, phone, email || null, service, message);
      return send(res, 201, { ok: true });
    } catch (error) {
      if (error.message === 'payload_too_large') return send(res, 413, { error: 'La demande est trop longue.' });
      if (error.message === 'invalid_json') return send(res, 400, { error: 'La demande est illisible.' });
      console.error('Erreur pendant l’enregistrement de la demande:', error.message);
      return send(res, 500, { error: 'La demande n’a pas pu être enregistrée. Contactez-nous par téléphone.' });
    }
  }
  if (url.pathname === '/api/leads' && req.method === 'GET') {
    if (!requireAdmin(req, res)) return;
    return send(res, 200, listLeads.all());
  }
  if (url.pathname === '/admin') {
    return serveFile(path.join(root, 'admin.html'), res);
  }
  if (req.method !== 'GET' && req.method !== 'HEAD') return send(res, 405, { error: 'Méthode non autorisée.' });
  let pathname;
  try { pathname = decodeURIComponent(url.pathname); } catch { return send(res, 400, { error: 'Adresse invalide.' }); }
  if (pathname === '/') pathname = '/index.html';
  const filePath = path.resolve(root, `.${pathname}`);
  if (!filePath.startsWith(root + path.sep)) return send(res, 403, { error: 'Accès interdit.' });
  return serveFile(filePath, res, req.method === 'HEAD');
});
function serveFile(filePath, res, head = false) {
  fs.readFile(filePath, (error, content) => {
    if (error) return send(res, 404, { error: 'Page introuvable.' });
    const ext = path.extname(filePath).toLowerCase();
    const cacheControl = ['.html', '.css', '.js'].includes(ext) ? 'no-store' : 'public, max-age=3600';
    res.writeHead(200, { 'Content-Type': mimeTypes[ext] || 'application/octet-stream', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': cacheControl });
    res.end(head ? undefined : content);
  });
}
server.listen(port, '0.0.0.0', () => console.log(`MG Multiservice disponible sur http://localhost:${port} (admin : mgadmin)`));
process.on('SIGINT', () => { db.close(); server.close(() => process.exit(0)); });
process.on('SIGTERM', () => { db.close(); server.close(() => process.exit(0)); });
