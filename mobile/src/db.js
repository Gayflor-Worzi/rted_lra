import * as SQLite from 'expo-sqlite'

let db = null

const COLUMNS_V2 = [
  ["status", "TEXT NOT NULL DEFAULT 'pending'"],
  ['attempts', 'INTEGER NOT NULL DEFAULT 0'],
  ['last_error', 'TEXT'],
  ['synced_at', 'TEXT'],
  ["local_transaction_id", "TEXT NOT NULL DEFAULT ''"],
]

export async function getDb() {
  if (db) return db
  db = await SQLite.openDatabaseAsync('retd_lra.db')
  await db.execAsync(`
    PRAGMA journal_mode = WAL;

    CREATE TABLE IF NOT EXISTS kv (
      key   TEXT PRIMARY KEY,
      value TEXT
    );

    CREATE TABLE IF NOT EXISTS outbox (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      kind       TEXT NOT NULL,
      payload    TEXT NOT NULL,
      created_at TEXT DEFAULT (datetime('now')),
      status     TEXT NOT NULL DEFAULT 'pending',
      attempts   INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      synced_at  TEXT,
      local_transaction_id TEXT NOT NULL DEFAULT ''
    );

    CREATE TABLE IF NOT EXISTS cached_tasks (
      id    INTEGER PRIMARY KEY,
      json  TEXT NOT NULL
    );
  `)

  // v1 → v2: pre-existing DBs keep the old narrower outbox table, so add the
  // sync-state columns idempotently instead of relying on CREATE IF NOT EXISTS.
  const cols = await db.getAllAsync("PRAGMA table_info('outbox')")
  const has = (name) => cols.some((c) => c.name === name)
  for (const [name, decl] of COLUMNS_V2) {
    if (!has(name)) {
      await db.execAsync(`ALTER TABLE outbox ADD COLUMN ${name} ${decl}`)
    }
  }

  return db
}

export async function kvGet(key) {
  const d = await getDb()
  const row = await d.getFirstAsync('SELECT value FROM kv WHERE key = ?', key)
  return row?.value ?? null
}

export async function kvSet(key, value) {
  const d = await getDb()
  await d.runAsync('INSERT OR REPLACE INTO kv (key, value) VALUES (?, ?)', key, value)
}

export async function kvDel(key) {
  const d = await getDb()
  await d.runAsync('DELETE FROM kv WHERE key = ?', key)
}

function localTxnId() {
  const d = new Date()
  const ymd = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`
  const rand = Math.random().toString(36).slice(2, 8).toUpperCase()
  return `MOB-${ymd}-${Date.now().toString(36).toUpperCase()}-${rand}`
}

/**
 * Queue a field transaction. Every row gets a unique local_transaction_id that
 * the server ledger uses to make retries idempotent — a resync can never create
 * a duplicate visit/discovery/receipt/action.
 */
export async function enqueue(kind, payload) {
  const d = await getDb()
  const id = localTxnId()
  const body = { ...payload, local_transaction_id: id }
  await d.runAsync(
    'INSERT INTO outbox (kind, payload, status, local_transaction_id) VALUES (?, ?, ?, ?)',
    kind,
    JSON.stringify(body),
    'pending',
    id,
  )
  return id
}

/** Number of records still awaiting a successful upload (pending/syncing/failed). */
export async function outboxCount() {
  const d = await getDb()
  const row = await d.getFirstAsync("SELECT COUNT(*) as n FROM outbox WHERE status IN ('pending','syncing','failed')")
  return row?.n ?? 0
}

export async function outboxStatusCounts() {
  const d = await getDb()
  const rows = await d.getAllAsync('SELECT status, COUNT(*) as n FROM outbox GROUP BY status')
  const m = { pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 }
  for (const r of rows) {
    if (m[r.status] !== undefined) m[r.status] = r.n
  }
  m.total = m.pending + m.syncing + m.synced + m.failed
  return m
}

/** Records that still need to reach the server (pending/syncing/failed), oldest first. */
export async function outboxUnsolved() {
  const d = await getDb()
  return await d.getAllAsync("SELECT * FROM outbox WHERE status != 'synced' ORDER BY id ASC")
}

export async function outboxFailed() {
  const d = await getDb()
  return await d.getAllAsync("SELECT * FROM outbox WHERE status = 'failed' ORDER BY id ASC")
}

export async function outboxSynced() {
  const d = await getDb()
  return await d.getAllAsync("SELECT * FROM outbox WHERE status = 'synced' ORDER BY id DESC")
}

export async function outboxAll() {
  const d = await getDb()
  return await d.getAllAsync('SELECT * FROM outbox ORDER BY id ASC')
}

export async function outboxMarkSyncing(id, attempts) {
  const d = await getDb()
  await d.runAsync("UPDATE outbox SET status = 'syncing', attempts = ? WHERE id = ?", attempts, id)
}

export async function outboxMarkSynced(id) {
  const d = await getDb()
  await d.runAsync("UPDATE outbox SET status = 'synced', last_error = NULL, synced_at = datetime('now') WHERE id = ?", id)
}

export async function outboxMarkFailed(id, error) {
  const d = await getDb()
  await d.runAsync("UPDATE outbox SET status = 'failed', last_error = ? WHERE id = ?", String(error ?? 'Unknown error').slice(0, 500), id)
}

export async function outboxDelete(id) {
  const d = await getDb()
  await d.runAsync('DELETE FROM outbox WHERE id = ?', id)
}

/** Drop successfully-synced receipt rows older than `days` to keep the queue tidy. */
export async function outboxPruneSynced(days = 7) {
  const d = await getDb()
  await d.runAsync(
    "DELETE FROM outbox WHERE status = 'synced' AND synced_at IS NOT NULL AND datetime(synced_at) < datetime('now', '-' || ? || ' days')",
    days,
  )
}

export async function cacheTasks(tasks) {
  const d = await getDb()
  await d.runAsync('DELETE FROM cached_tasks')
  for (const t of tasks) {
    await d.runAsync('INSERT INTO cached_tasks (id, json) VALUES (?, ?)', t.id, JSON.stringify(t))
  }
}

export async function getCachedTasks() {
  const d = await getDb()
  const rows = await d.getAllAsync('SELECT json FROM cached_tasks')
  return rows.map((r) => JSON.parse(r.json))
}