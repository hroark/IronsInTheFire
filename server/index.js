const crypto = require("crypto");
const path = require("path");
const express = require("express");
const mysql = require("mysql2/promise");

require("dotenv").config({ path: path.join(__dirname, "..", ".env") });

const PORT = Number(process.env.PORT || 3000);
const COOKIE = "notes_session";
const SESSION_MS = 14 * 24 * 60 * 60 * 1000;
const ROOT = path.join(__dirname, "..");

let pool = null;
let databaseError = "MySQL is not configured yet.";

function databaseName() {
  const name = process.env.MYSQL_DATABASE || "";
  if (!/^[A-Za-z0-9_]+$/.test(name)) {
    throw new Error("MYSQL_DATABASE must contain only letters, numbers, and underscores.");
  }
  return name;
}

function connectionOptions(database) {
  return {
    host: process.env.MYSQL_HOST || "127.0.0.1",
    port: Number(process.env.MYSQL_PORT || 3306),
    user: process.env.MYSQL_USER || "",
    password: process.env.MYSQL_PASSWORD || "",
    database,
    waitForConnections: true,
    connectionLimit: 10,
    charset: "utf8mb4",
    timezone: "Z"
  };
}

async function connectDatabase() {
  if (!process.env.MYSQL_USER || !process.env.MYSQL_DATABASE) {
    throw new Error("Set MYSQL_USER and MYSQL_DATABASE in .env.");
  }
  const name = databaseName();
  try {
    pool = mysql.createPool(connectionOptions(name));
    await pool.query("SELECT 1");
  } catch (error) {
    if (!error || error.code !== "ER_BAD_DB_ERROR") throw error;
    const connection = await mysql.createConnection(connectionOptions(undefined));
    await connection.query(
      "CREATE DATABASE IF NOT EXISTS `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    await connection.end();
    pool = mysql.createPool(connectionOptions(name));
    await pool.query("SELECT 1");
  }

  await pool.query(`
    CREATE TABLE IF NOT EXISTS account (
      id TINYINT PRIMARY KEY,
      password_hash VARCHAR(255) NOT NULL,
      password_salt VARCHAR(255) NOT NULL,
      created_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS sessions (
      token_hash CHAR(64) PRIMARY KEY,
      expires_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS entries (
      id VARCHAR(64) PRIMARY KEY,
      entry_type ENUM('note', 'todo') NOT NULL,
      title VARCHAR(160) NOT NULL,
      format ENUM('plain', 'markdown') NULL,
      body MEDIUMTEXT NULL,
      items JSON NULL,
      created_at BIGINT NOT NULL,
      updated_at BIGINT NOT NULL,
      INDEX entries_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS journal_seal (
      id TINYINT PRIMARY KEY,
      kdf_iterations INT NOT NULL,
      salt VARCHAR(255) NOT NULL,
      verifier_iv VARCHAR(255) NOT NULL,
      verifier VARCHAR(512) NOT NULL,
      created_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS journal_entries (
      id VARCHAR(64) PRIMARY KEY,
      iv VARCHAR(255) NOT NULL,
      ciphertext MEDIUMTEXT NOT NULL,
      created_at BIGINT NOT NULL,
      updated_at BIGINT NOT NULL,
      INDEX journal_entries_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
  databaseError = "";
}

function hashToken(token) {
  return crypto.createHash("sha256").update(token).digest("hex");
}

function scryptPassword(passphrase, salt) {
  return new Promise((resolve, reject) => {
    crypto.scrypt(passphrase, salt, 64, { N: 16384, r: 8, p: 1 }, (error, hash) => {
      if (error) reject(error);
      else resolve(hash);
    });
  });
}

async function createPasswordRecord(passphrase) {
  const salt = crypto.randomBytes(16);
  const hash = await scryptPassword(passphrase, salt);
  return { salt: salt.toString("base64"), hash: hash.toString("base64") };
}

async function passwordMatches(passphrase, saltBase64, hashBase64) {
  const salt = Buffer.from(saltBase64, "base64");
  const expected = Buffer.from(hashBase64, "base64");
  const actual = await scryptPassword(passphrase, salt);
  return expected.length === actual.length && crypto.timingSafeEqual(expected, actual);
}

function readCookie(req, name) {
  const header = req.headers.cookie || "";
  for (const part of header.split(";")) {
    const text = part.trim();
    const eq = text.indexOf("=");
    if (eq === -1) continue;
    if (text.slice(0, eq) === name) return decodeURIComponent(text.slice(eq + 1));
  }
  return "";
}

function sendCookie(res, token) {
  const secure = process.env.COOKIE_SECURE === "true" ? "; Secure" : "";
  res.setHeader(
    "Set-Cookie",
    COOKIE +
      "=" +
      encodeURIComponent(token) +
      "; HttpOnly; SameSite=Strict; Path=/; Max-Age=" +
      Math.floor(SESSION_MS / 1000) +
      secure
  );
}

function clearCookie(res) {
  const secure = process.env.COOKIE_SECURE === "true" ? "; Secure" : "";
  res.setHeader("Set-Cookie", COOKIE + "=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0" + secure);
}

async function currentSession(req) {
  if (!pool) return false;
  const token = readCookie(req, COOKIE);
  if (!token) return false;
  const [rows] = await pool.query("SELECT expires_at FROM sessions WHERE token_hash = ? LIMIT 1", [hashToken(token)]);
  if (!rows.length || Number(rows[0].expires_at) < Date.now()) return false;
  return true;
}

async function startSession(res) {
  const token = crypto.randomBytes(32).toString("base64url");
  await pool.query("INSERT INTO sessions (token_hash, expires_at) VALUES (?, ?)", [
    hashToken(token),
    Date.now() + SESSION_MS
  ]);
  sendCookie(res, token);
}

const attempts = new Map();

function attemptKey(req) {
  return req.ip || req.socket.remoteAddress || "unknown";
}

function lockedOut(req) {
  const row = attempts.get(attemptKey(req));
  if (!row) return false;
  if (Date.now() > row.reset) {
    attempts.delete(attemptKey(req));
    return false;
  }
  return row.count >= 8;
}

function recordFailure(req) {
  const key = attemptKey(req);
  const row = attempts.get(key);
  if (!row || Date.now() > row.reset) {
    attempts.set(key, { count: 1, reset: Date.now() + 60 * 1000 });
    return;
  }
  row.count += 1;
}

function clearFailures(req) {
  attempts.delete(attemptKey(req));
}

function validPassphrase(value) {
  return typeof value === "string" && value.length >= 8 && value.length <= 200;
}

function validId(value) {
  return typeof value === "string" && /^[A-Za-z0-9-]{8,64}$/.test(value);
}

function validBlob(value, max) {
  return typeof value === "string" && value.length > 0 && value.length <= max && /^[A-Za-z0-9+/=_-]+$/.test(value);
}

function normalizeSeal(body) {
  const iterations = Number(body && body.iterations);
  if (!Number.isInteger(iterations) || iterations < 100000 || iterations > 1000000) return null;
  if (!body || !validBlob(body.salt, 128) || !validBlob(body.verifierIv, 64) || !validBlob(body.verifier, 512)) return null;
  return {
    iterations,
    salt: body.salt,
    verifierIv: body.verifierIv,
    verifier: body.verifier
  };
}

function normalizeCipherEntry(entry) {
  if (!entry || !validId(entry.id) || !validBlob(entry.iv, 64) || !validBlob(entry.ciphertext, 700000)) return null;
  const createdAt = Number(entry.createdAt);
  const updatedAt = Number(entry.updatedAt);
  if (!Number.isFinite(createdAt) || !Number.isFinite(updatedAt)) return null;
  return { id: entry.id, iv: entry.iv, ciphertext: entry.ciphertext, createdAt, updatedAt };
}

async function writeSeal(executor, seal) {
  await executor.query(
    `INSERT INTO journal_seal (id, kdf_iterations, salt, verifier_iv, verifier, created_at)
     VALUES (1, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       kdf_iterations = VALUES(kdf_iterations),
       salt = VALUES(salt),
       verifier_iv = VALUES(verifier_iv),
       verifier = VALUES(verifier)`,
    [seal.iterations, seal.salt, seal.verifierIv, seal.verifier, Date.now()]
  );
}

async function writeJournalEntry(executor, entry) {
  await executor.query(
    `INSERT INTO journal_entries (id, iv, ciphertext, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       iv = VALUES(iv),
       ciphertext = VALUES(ciphertext),
       updated_at = VALUES(updated_at)`,
    [entry.id, entry.iv, entry.ciphertext, entry.createdAt, entry.updatedAt]
  );
}

function normalizeItem(item) {
  if (!item || typeof item !== "object" || typeof item.text !== "string" || !validId(item.id)) return null;
  return { id: item.id, text: item.text.slice(0, 500), done: Boolean(item.done) };
}

function normalizeEntry(entry) {
  if (!entry || typeof entry !== "object" || !validId(entry.id)) return null;
  if (entry.type !== "note" && entry.type !== "todo") return null;
  const createdAt = Number(entry.createdAt);
  const updatedAt = Number(entry.updatedAt);
  const base = {
    id: entry.id,
    type: entry.type,
    title: String(entry.title || "").slice(0, 160),
    createdAt: Number.isFinite(createdAt) ? createdAt : Date.now(),
    updatedAt: Number.isFinite(updatedAt) ? updatedAt : Date.now()
  };
  if (entry.type === "note") {
    return {
      ...base,
      format: entry.format === "markdown" ? "markdown" : "plain",
      body: String(entry.body || "").slice(0, 200000)
    };
  }
  const items = Array.isArray(entry.items) ? entry.items.map(normalizeItem).filter(Boolean).slice(0, 200) : [];
  return { ...base, items };
}

function rowToEntry(row) {
  const items = row.items
    ? typeof row.items === "string"
      ? JSON.parse(row.items)
      : row.items
    : [];
  return normalizeEntry({
    id: row.id,
    type: row.entry_type,
    title: row.title,
    format: row.format,
    body: row.body || "",
    items: Array.isArray(items) ? items : [],
    createdAt: Number(row.created_at),
    updatedAt: Number(row.updated_at)
  });
}

async function saveEntry(entry) {
  await pool.query(
    `INSERT INTO entries (id, entry_type, title, format, body, items, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       entry_type = VALUES(entry_type),
       title = VALUES(title),
       format = VALUES(format),
       body = VALUES(body),
       items = VALUES(items),
       updated_at = VALUES(updated_at)`,
    [
      entry.id,
      entry.type,
      entry.title,
      entry.type === "note" ? entry.format : null,
      entry.type === "note" ? entry.body : null,
      entry.type === "todo" ? JSON.stringify(entry.items) : null,
      entry.createdAt,
      entry.updatedAt
    ]
  );
}

function requireDatabase(req, res, next) {
  if (!pool || databaseError) {
    res.status(503).json({ error: databaseError || "MySQL is not connected." });
    return;
  }
  next();
}

async function requireAuth(req, res, next) {
  try {
    if (!(await currentSession(req))) {
      res.status(401).json({ error: "Sign in again." });
      return;
    }
    next();
  } catch (error) {
    res.status(500).json({ error: "The log could not be opened." });
  }
}

const route = (handler) => (req, res, next) => Promise.resolve(handler(req, res, next)).catch(next);

const app = express();
app.disable("x-powered-by");
app.set("trust proxy", process.env.TRUST_PROXY === "true" ? 1 : false);
app.use((req, res, next) => {
  const large = req.path === "/notes/api/import" || req.path === "/notes/api/journal/rekey";
  return express.json({ limit: large ? "32mb" : "2mb" })(req, res, next);
});

app.get("/notes/api/session", requireDatabase, route(async (req, res) => {
  const [accounts] = await pool.query("SELECT id FROM account WHERE id = 1");
  res.json({
    database: "ok",
    needsSetup: accounts.length === 0,
    authenticated: await currentSession(req)
  });
}));

app.post("/notes/api/setup", requireDatabase, route(async (req, res) => {
  if (!validPassphrase(req.body && req.body.passphrase)) {
    res.status(400).json({ error: "Use at least 8 characters." });
    return;
  }
  const [accounts] = await pool.query("SELECT id FROM account WHERE id = 1");
  if (accounts.length) {
    res.status(409).json({ error: "This log already has a passphrase." });
    return;
  }
  const record = await createPasswordRecord(req.body.passphrase);
  try {
    await pool.query("INSERT INTO account (id, password_hash, password_salt, created_at) VALUES (1, ?, ?, ?)", [
      record.hash,
      record.salt,
      Date.now()
    ]);
  } catch (error) {
    if (error && error.code === "ER_DUP_ENTRY") {
      res.status(409).json({ error: "This log already has a passphrase." });
      return;
    }
    throw error;
  }
  await startSession(res);
  res.status(201).json({ ok: true });
}));

app.post("/notes/api/login", requireDatabase, route(async (req, res) => {
  if (lockedOut(req)) {
    res.status(429).json({ error: "Too many attempts. Wait a minute and try again." });
    return;
  }
  recordFailure(req);
  const passphrase = req.body && req.body.passphrase;
  const [accounts] = await pool.query("SELECT password_hash, password_salt FROM account WHERE id = 1");
  const account = accounts[0];
  const matches = account && validPassphrase(passphrase)
    ? await passwordMatches(passphrase, account.password_salt, account.password_hash)
    : false;
  if (!matches) {
    res.status(401).json({ error: "That passphrase is not right." });
    return;
  }
  clearFailures(req);
  await pool.query("DELETE FROM sessions WHERE expires_at < ?", [Date.now()]);
  await startSession(res);
  res.json({ ok: true });
}));

app.post("/notes/api/logout", requireDatabase, route(async (req, res) => {
  const token = readCookie(req, COOKIE);
  if (token) await pool.query("DELETE FROM sessions WHERE token_hash = ?", [hashToken(token)]);
  clearCookie(res);
  res.json({ ok: true });
}));

app.put("/notes/api/passphrase", requireDatabase, requireAuth, route(async (req, res) => {
  const current = req.body && req.body.current;
  const next = req.body && req.body.next;
  if (!validPassphrase(next)) {
    res.status(400).json({ error: "Use at least 8 characters." });
    return;
  }
  const [accounts] = await pool.query("SELECT password_hash, password_salt FROM account WHERE id = 1");
  const account = accounts[0];
  if (!account || !(await passwordMatches(String(current || ""), account.password_salt, account.password_hash))) {
    res.status(401).json({ error: "The current passphrase is not right." });
    return;
  }
  const record = await createPasswordRecord(next);
  await pool.query("UPDATE account SET password_hash = ?, password_salt = ? WHERE id = 1", [record.hash, record.salt]);
  await pool.query("DELETE FROM sessions");
  await startSession(res);
  res.json({ ok: true });
}));

app.get("/notes/api/entries", requireDatabase, requireAuth, route(async (req, res) => {
  const [rows] = await pool.query("SELECT * FROM entries ORDER BY updated_at DESC");
  res.json({ entries: rows.map(rowToEntry).filter(Boolean) });
}));

app.put("/notes/api/entries/:id", requireDatabase, requireAuth, route(async (req, res) => {
  const entry = normalizeEntry({ ...req.body, id: req.params.id });
  if (!entry) {
    res.status(400).json({ error: "That entry could not be saved." });
    return;
  }
  await saveEntry(entry);
  res.json({ entry });
}));

app.delete("/notes/api/entries/:id", requireDatabase, requireAuth, route(async (req, res) => {
  if (!validId(req.params.id)) {
    res.status(400).json({ error: "That entry could not be deleted." });
    return;
  }
  await pool.query("DELETE FROM entries WHERE id = ?", [req.params.id]);
  res.json({ ok: true });
}));

app.delete("/notes/api/entries", requireDatabase, requireAuth, route(async (req, res) => {
  await pool.query("DELETE FROM entries");
  res.json({ ok: true });
}));

app.post("/notes/api/import", requireDatabase, requireAuth, route(async (req, res) => {
  const source = req.body && Array.isArray(req.body.entries) ? req.body.entries : null;
  if (!source) {
    res.status(400).json({ error: "That file is not a log backup." });
    return;
  }
  const entries = [];
  for (const raw of source) {
    if (raw && raw.type === "note" && String(raw.body || "").length > 200000) {
      res.status(400).json({ error: "That backup contains a note longer than the log can store. Nothing was replaced." });
      return;
    }
    if (raw && raw.type === "todo" && Array.isArray(raw.items) && raw.items.length > 200) {
      res.status(400).json({ error: "That backup contains a list with more than 200 items. Nothing was replaced." });
      return;
    }
    const entry = normalizeEntry(raw);
    if (!entry) {
      res.status(400).json({ error: "That backup contains an entry that cannot be stored. Nothing was replaced." });
      return;
    }
    entries.push(entry);
  }
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();
    await connection.query("DELETE FROM entries");
    for (const entry of entries) await saveEntryOn(connection, entry);
    await connection.commit();
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
  res.json({ entries });
}));

app.get("/notes/api/journal", requireDatabase, requireAuth, route(async (req, res) => {
  const [seals] = await pool.query("SELECT id FROM journal_seal WHERE id = 1");
  const [counts] = await pool.query("SELECT COUNT(*) AS count FROM journal_entries");
  res.json({ configured: seals.length > 0, entryCount: Number(counts[0].count) });
}));

app.get("/notes/api/journal/seal", requireDatabase, requireAuth, route(async (req, res) => {
  const [rows] = await pool.query(
    "SELECT kdf_iterations, salt, verifier_iv, verifier FROM journal_seal WHERE id = 1"
  );
  if (!rows.length) {
    res.status(404).json({ error: "The journal does not have a password yet." });
    return;
  }
  res.json({
    iterations: rows[0].kdf_iterations,
    salt: rows[0].salt,
    verifierIv: rows[0].verifier_iv,
    verifier: rows[0].verifier
  });
}));

app.put("/notes/api/journal/seal", requireDatabase, requireAuth, route(async (req, res) => {
  const seal = normalizeSeal(req.body);
  if (!seal) {
    res.status(400).json({ error: "The journal password could not be saved." });
    return;
  }
  const [existing] = await pool.query("SELECT id FROM journal_seal WHERE id = 1");
  if (existing.length) {
    res.status(409).json({ error: "The journal already has a password." });
    return;
  }
  await writeSeal(pool, seal);
  res.status(201).json({ ok: true });
}));

app.get("/notes/api/journal/entries", requireDatabase, requireAuth, route(async (req, res) => {
  const [rows] = await pool.query("SELECT id, iv, ciphertext, created_at, updated_at FROM journal_entries ORDER BY updated_at DESC");
  res.json({
    entries: rows.map((row) => ({
      id: row.id,
      iv: row.iv,
      ciphertext: row.ciphertext,
      createdAt: Number(row.created_at),
      updatedAt: Number(row.updated_at)
    }))
  });
}));

app.put("/notes/api/journal/entries/:id", requireDatabase, requireAuth, route(async (req, res) => {
  const entry = normalizeCipherEntry({ ...req.body, id: req.params.id });
  if (!entry) {
    res.status(400).json({ error: "That journal entry could not be saved." });
    return;
  }
  const [seals] = await pool.query("SELECT id FROM journal_seal WHERE id = 1");
  if (!seals.length) {
    res.status(409).json({ error: "Choose a journal password first." });
    return;
  }
  await writeJournalEntry(pool, entry);
  res.json({ ok: true });
}));

app.delete("/notes/api/journal/entries/:id", requireDatabase, requireAuth, route(async (req, res) => {
  if (!validId(req.params.id)) {
    res.status(400).json({ error: "That journal entry could not be deleted." });
    return;
  }
  await pool.query("DELETE FROM journal_entries WHERE id = ?", [req.params.id]);
  res.json({ ok: true });
}));

app.put("/notes/api/journal/rekey", requireDatabase, requireAuth, route(async (req, res) => {
  const seal = normalizeSeal(req.body && req.body.seal);
  const source = req.body && Array.isArray(req.body.entries) ? req.body.entries : null;
  if (!seal || !source) {
    res.status(400).json({ error: "The journal password could not be changed." });
    return;
  }
  const entries = source.map(normalizeCipherEntry).filter(Boolean);
  if (entries.length !== source.length) {
    res.status(400).json({ error: "The journal password could not be changed." });
    return;
  }
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();
    await writeSeal(connection, seal);
    await connection.query("DELETE FROM journal_entries");
    for (const entry of entries) await writeJournalEntry(connection, entry);
    await connection.commit();
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
  res.json({ ok: true });
}));

async function saveEntryOn(executor, entry) {
  await executor.query(
    `INSERT INTO entries (id, entry_type, title, format, body, items, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      entry.id,
      entry.type,
      entry.title,
      entry.type === "note" ? entry.format : null,
      entry.type === "note" ? entry.body : null,
      entry.type === "todo" ? JSON.stringify(entry.items) : null,
      entry.createdAt,
      entry.updatedAt
    ]
  );
}

app.use((error, req, res, next) => {
  if (res.headersSent) {
    next(error);
    return;
  }
  const tooLarge = error && (error.type === "entity.too.large" || error.status === 413);
  const importTooLarge = tooLarge && req.path === "/notes/api/import";
  res.status(tooLarge ? 413 : 500).json({
    error: importTooLarge
      ? "That backup is too large to restore. Nothing was replaced."
      : tooLarge
        ? "That note is too large to save."
        : "The log could not be saved."
  });
});

app.use((req, res, next) => {
  if (req.path === "/server" || req.path.startsWith("/server/") || req.path === "/node_modules" || req.path.startsWith("/node_modules/") || req.path === "/package.json" || req.path === "/package-lock.json") {
    res.status(404).end();
    return;
  }
  next();
});

app.use(express.static(ROOT, { dotfiles: "ignore", index: ["index.html"] }));

connectDatabase()
  .then(() => {
    app.listen(PORT, "0.0.0.0", () => {
      console.log("Site and private notes are available on port " + PORT);
    });
  })
  .catch((error) => {
    databaseError = error.message || "MySQL is not connected.";
    console.error(databaseError);
    app.listen(PORT, "0.0.0.0", () => {
      console.log("Site is available on port " + PORT + " without the notes database.");
    });
  });
