import * as SQLite from 'expo-sqlite'

let db = null

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
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS cached_tasks (
      id    INTEGER PRIMARY KEY,
      json  TEXT NOT NULL
    );
  `)
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

export async function enqueue(kind, payload) {
  const d = await getDb()
  await d.runAsync('INSERT INTO outbox (kind, payload) VALUES (?, ?)', kind, JSON.stringify(payload))
}

export async function outboxCount() {
  const d = await getDb()
  const row = await d.getFirstAsync('SELECT COUNT(*) as n FROM outbox')
  return row?.n ?? 0
}

export async function outboxAll() {
  const d = await getDb()
  return await d.getAllAsync('SELECT * FROM outbox ORDER BY id ASC')
}

export async function outboxDelete(id) {
  const d = await getDb()
  await d.runAsync('DELETE FROM outbox WHERE id = ?', id)
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
