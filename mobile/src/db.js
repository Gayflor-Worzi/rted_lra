import * as SQLite from 'expo-sqlite'
import * as SecureStore from 'expo-secure-store'
import * as Crypto from 'expo-crypto'
import { Platform } from 'react-native'
import {
  insertOutbox,
  migrateOutboxOwnership,
  getOutboxCounts,
  getOutboxRows,
  getOutboxRow,
  markOutboxPending,
  markOutboxSyncing,
  markOutboxSynced,
  markOutboxFailed,
  deleteOutboxRow,
  pruneOutbox,
} from './outboxStore.mjs'

let db = null
let dbPromise = null

const DB_KEY_NAME = 'retd_lra.db.key'
const PENDING_DB_KEY_NAME = 'retd_lra.db.pending_key'
const AUTH_TOKEN_KEY = 'retd_lra.auth.token'
const LEGACY_DB_NAME = 'retd_lra.db'
const SECURE_DB_NAME = 'retd_lra_encrypted.db'
const SECURE_STORE_OPTIONS = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

export const OFFLINE_CACHE_TTL_MS = {
  dashboard: 24 * 60 * 60 * 1000,
  tasks: 48 * 60 * 60 * 1000,
  bills: 48 * 60 * 60 * 1000,
  discoveries: 48 * 60 * 60 * 1000,
  valuations: 48 * 60 * 60 * 1000,
  notices: 48 * 60 * 60 * 1000,
  gps: 48 * 60 * 60 * 1000,
  organization: 7 * 24 * 60 * 60 * 1000,
}

const OFFLINE_CACHE_MODULES = new Set(Object.keys(OFFLINE_CACHE_TTL_MS))
const OFFLINE_TASK_BILL_FIELDS = [
  'id',
  'bill_number',
  'document_number',
  'property_id',
  'property_address',
  'tax_period',
  'property_classification',
  'property_type',
  'gps_coordinate',
  'gps_accuracy',
  'gps_captured_at',
]

// Serialize ALL statements through one promise chain. expo-sqlite's promise API
// is not safe for concurrent calls on a single connection: two overlapping
// runAsync/getAllAsync on the same cached NativeStatement let the Android bridge
// release the statement mid-flight, throwing
//   "cannot cast to type class ...NativeStatement (received Integer)"
//   "Cannot use shared object that was already released"
// Every db.js function funnels through enqueueDb so no two statements can
// overlap, removing that crash class entirely. Keep the chain alive on errors so
// one failed statement can't deadlock the queue, while still rejecting callers.
let queue = Promise.resolve()

export function enqueueDb(work) {
  const run = queue.then(async () => {
    const d = await getDb()
    return work(d)
  })
  queue = run.catch(() => {})
  return run
}

const COLUMNS_V2 = [
  ["status", "TEXT NOT NULL DEFAULT 'pending'"],
  ['attempts', 'INTEGER NOT NULL DEFAULT 0'],
  ['last_error', 'TEXT'],
  ['synced_at', 'TEXT'],
  ['updated_at', 'TEXT'],
  ["local_transaction_id", "TEXT NOT NULL DEFAULT ''"],
  ["parent_transaction_id", "TEXT NOT NULL DEFAULT ''"],
]
async function openEncryptedDatabase(key, databaseName = SECURE_DB_NAME) {
  const connection = await SQLite.openDatabaseAsync(databaseName)
  try {
    const cipher = await connection.getFirstAsync('PRAGMA cipher_version')
    if (!cipher?.cipher_version) {
      throw new Error('Encrypted offline storage is unavailable in this app build.')
    }
    await connection.execAsync(`PRAGMA key = "x'${key}'"`)
    await connection.getFirstAsync('SELECT count(*) FROM sqlite_master')
    return connection
  } catch (error) {
    await connection.closeAsync()
    throw error
  }
}

async function inspectLegacyDatabase() {
  const connection = await SQLite.openDatabaseAsync(LEGACY_DB_NAME)
  try {
    const tables = await connection.getFirstAsync(
      "SELECT count(*) AS count FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
    )
    return { connection, hasData: Number(tables?.count) > 0 }
  } catch (error) {
    await connection.closeAsync()
    throw new Error('Existing offline data could not be inspected safely.', { cause: error })
  }
}

async function localDataCounts(connection) {
  const tables = await connection.getAllAsync(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
  )
  const counts = {}
  for (const { name } of tables) {
    const escapedName = name.replace(/"/g, '""')
    counts[name] = Number((await connection.getFirstAsync(`SELECT count(*) AS count FROM "${escapedName}"`))?.count)
  }
  return counts
}

async function encryptExistingDatabase(key, legacy) {
  const source = legacy.connection
  const targetProbe = await SQLite.openDatabaseAsync(SECURE_DB_NAME)
  const targetPath = targetProbe.databasePath
  await targetProbe.closeAsync()
  await SQLite.deleteDatabaseAsync(SECURE_DB_NAME)
  let sourceClosed = false

  try {
    const cipher = await source.getFirstAsync('PRAGMA cipher_version')
    if (!cipher?.cipher_version) {
      throw new Error('Encrypted offline storage is unavailable in this app build.')
    }

    const sourceCounts = await localDataCounts(source)
    await source.execAsync('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode = DELETE;')
    const escapedPath = targetPath.replace(/'/g, "''")
    await source.execAsync(
      `ATTACH DATABASE '${escapedPath}' AS encrypted KEY "x'${key}'"; ` +
      "SELECT sqlcipher_export('encrypted'); DETACH DATABASE encrypted;",
    )
    await source.closeAsync()
    sourceClosed = true

    const encrypted = await openEncryptedDatabase(key)
    const integrity = await encrypted.getFirstAsync('PRAGMA integrity_check')
    if (integrity?.integrity_check !== 'ok') {
      await encrypted.closeAsync()
      throw new Error('Encrypted offline database integrity verification failed.')
    }
    const targetCounts = await localDataCounts(encrypted)
    if (JSON.stringify(sourceCounts) !== JSON.stringify(targetCounts)) {
      await encrypted.closeAsync()
      throw new Error('Offline data was not fully preserved during encryption.')
    }
    await encrypted.execAsync('PRAGMA journal_mode = WAL')
    return encrypted
  } catch (error) {
    if (!sourceClosed) await source.closeAsync()
    throw new Error('Existing offline data could not be encrypted safely.', { cause: error })
  }
}

async function openOrMigrateEncryptedDatabase() {
  const activeKey = await SecureStore.getItemAsync(DB_KEY_NAME, SECURE_STORE_OPTIONS)
  const pendingKey = activeKey
    ? null
    : await SecureStore.getItemAsync(PENDING_DB_KEY_NAME, SECURE_STORE_OPTIONS)
  const key = activeKey || pendingKey || [...Crypto.getRandomBytes(32)]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')

  if (!activeKey && !pendingKey) {
    await SecureStore.setItemAsync(PENDING_DB_KEY_NAME, key, SECURE_STORE_OPTIONS)
  }

  const legacy = activeKey ? null : await inspectLegacyDatabase()

  let connection
  try {
    connection = await openEncryptedDatabase(key)
  } catch (keyError) {
    if (activeKey) {
      throw new Error('Encrypted offline data could not be opened with this device key.', { cause: keyError })
    }

    if (legacy?.hasData) {
      return { connection: await encryptExistingDatabase(key, legacy), key }
    }
    if (legacy?.connection) await legacy.connection.closeAsync()
    throw new Error('Encrypted offline storage could not be opened.', { cause: keyError })
  }

  const tables = await connection.getFirstAsync(
    "SELECT count(*) AS count FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
  )
  if (activeKey && Number(tables?.count) === 0) {
    await connection.closeAsync()
    throw new Error('The encrypted offline database is missing its expected schema.')
  }
  if (activeKey) {
    const required = await connection.getFirstAsync(
      "SELECT count(*) AS count FROM sqlite_master WHERE type = 'table' AND name IN ('kv', 'outbox')",
    )
    if (Number(required?.count) !== 2) {
      await connection.closeAsync()
      throw new Error('The encrypted offline database is missing required tables.')
    }
  }
  if (!activeKey && legacy?.hasData) {
    const sourceCounts = await localDataCounts(legacy.connection)
    const targetCounts = await localDataCounts(connection)
    if (Number(tables?.count) === 0 || JSON.stringify(sourceCounts) !== JSON.stringify(targetCounts)) {
      await connection.closeAsync()
      const legacyConnection = legacy.connection
      legacy.connection = null
      return { connection: await encryptExistingDatabase(key, { connection: legacyConnection }), key }
    }
  }
  await connection.execAsync('PRAGMA journal_mode = WAL')
  if (legacy?.connection) await legacy.connection.closeAsync()
  return { connection, key, cleanupLegacy: !activeKey }
}

async function migrateLegacyAuthToken(connection) {
  const legacyToken = await connection.getFirstAsync("SELECT value FROM kv WHERE key = 'token'")
  if (legacyToken?.value) {
    const secureToken = await SecureStore.getItemAsync(AUTH_TOKEN_KEY, SECURE_STORE_OPTIONS)
    if (!secureToken) {
      await SecureStore.setItemAsync(AUTH_TOKEN_KEY, legacyToken.value, SECURE_STORE_OPTIONS)
    }
  }
  await connection.runAsync("DELETE FROM kv WHERE key = 'token'")
}

export function getDb() {
  // Share a single init promise so concurrent callers (multiple screens mount at
  // once) never run schema migration concurrently, which would attempt a duplicate
  // `ALTER TABLE ... ADD COLUMN status` and fail with "duplicate column name".
  if (!dbPromise) {
    dbPromise = (async () => {
      const { connection: d, key, cleanupLegacy } = Platform.OS === 'web'
        ? { connection: await SQLite.openDatabaseAsync(LEGACY_DB_NAME), key: null, cleanupLegacy: false }
        : await openOrMigrateEncryptedDatabase()
      await d.execAsync(`
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
          updated_at TEXT,
          local_transaction_id TEXT NOT NULL DEFAULT '',
          parent_transaction_id TEXT NOT NULL DEFAULT '',
          owner_user_id TEXT
        );

        CREATE TABLE IF NOT EXISTS cached_tasks (
          id    INTEGER PRIMARY KEY,
          json  TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS read_cache (
          module    TEXT NOT NULL,
          cache_key TEXT NOT NULL,
          user_id   TEXT NOT NULL,
          payload   TEXT,
          synced_at TEXT NOT NULL,
          PRIMARY KEY (module, cache_key, user_id)
        );
      `)

      // v1 → v2: pre-existing DBs keep the old narrower outbox table, so add the
      // sync-state columns idempotently instead of relying on CREATE IF NOT EXISTS.
      const cols = await d.getAllAsync("PRAGMA table_info('outbox')")
      const has = (name) => cols.some((c) => c.name === name)
      for (const [name, decl] of COLUMNS_V2) {
        if (!has(name)) {
          await d.execAsync(`ALTER TABLE outbox ADD COLUMN ${name} ${decl}`)
        }
      }
      await migrateOutboxOwnership(d)

      await d.runAsync('DELETE FROM cached_tasks')
      if (Platform.OS !== 'web') {
        await migrateLegacyAuthToken(d)
        if (cleanupLegacy) await SQLite.deleteDatabaseAsync(LEGACY_DB_NAME)
        await SecureStore.setItemAsync(DB_KEY_NAME, key, SECURE_STORE_OPTIONS)
        await SecureStore.deleteItemAsync(PENDING_DB_KEY_NAME, SECURE_STORE_OPTIONS)
      }
      db = d
      return d
    })().catch((error) => {
      dbPromise = null
      throw error
    })
  }
  return dbPromise
}

export async function getAuthToken() {
  if (Platform.OS === 'web') return kvGet('token')
  return SecureStore.getItemAsync(AUTH_TOKEN_KEY, SECURE_STORE_OPTIONS)
}

export async function setAuthToken(token) {
  if (Platform.OS === 'web') return kvSet('token', token)
  return SecureStore.setItemAsync(AUTH_TOKEN_KEY, token, SECURE_STORE_OPTIONS)
}

export async function deleteAuthToken() {
  if (Platform.OS === 'web') return kvDel('token')
  return SecureStore.deleteItemAsync(AUTH_TOKEN_KEY, SECURE_STORE_OPTIONS)
}

export async function kvGet(key) {
  return enqueueDb(async (d) => (await d.getFirstAsync('SELECT value FROM kv WHERE key = ?', key))?.value ?? null)
}

export async function kvSet(key, value) {
  return enqueueDb((d) => d.runAsync('INSERT OR REPLACE INTO kv (key, value) VALUES (?, ?)', key, value))
}

export async function kvDel(key) {
  return enqueueDb((d) => d.runAsync('DELETE FROM kv WHERE key = ?', key))
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
export async function enqueue(userId, kind, payload) {
  return enqueueRow(userId, kind, payload, '')
}

/**
 * Queue a transaction that depends on a parent row (e.g. an evidence photo that
 * needs the discovery's server id). The syncer defers the child until the
 * parent has synced and its server id is known.
 */
export async function enqueueDependent(userId, kind, payload, parentTransactionId) {
  return enqueueRow(userId, kind, payload, parentTransactionId)
}

async function enqueueRow(userId, kind, payload, parentTransactionId) {
  const id = localTxnId()
  await enqueueDb((d) => insertOutbox(d, userId, kind, payload, parentTransactionId, id))
  return id
}

export async function outboxMarkPending(id, userId) {
  return enqueueDb((d) => markOutboxPending(d, id, userId))
}

/** Number of records still awaiting a successful upload (pending/syncing/failed). */
export async function outboxCount(userId) {
  return enqueueDb(async (d) => {
    const counts = await getOutboxCounts(d, userId)
    return counts.pending + counts.syncing + counts.failed
  })
}

export async function outboxStatusCounts(userId) {
  return enqueueDb((d) => getOutboxCounts(d, userId))
}

/** Records that still need to reach the server (pending/syncing/failed), oldest first. */
export async function outboxUnsolved(userId) {
  return enqueueDb((d) => getOutboxRows(d, userId, 'unsolved'))
}

/** A single queued row by id (used by per-record retry). */
export async function outboxById(id, userId) {
  return enqueueDb((d) => getOutboxRow(d, id, userId))
}

export async function outboxFailed(userId) {
  return enqueueDb((d) => getOutboxRows(d, userId, 'failed'))
}

export async function outboxSynced(userId) {
  return enqueueDb((d) => getOutboxRows(d, userId, 'synced'))
}

export async function outboxAll(userId) {
  return enqueueDb((d) => getOutboxRows(d, userId))
}

export async function outboxMarkSyncing(id, attempts, userId) {
  return enqueueDb((d) => markOutboxSyncing(d, id, attempts, userId))
}

export async function outboxMarkSynced(id, userId) {
  return enqueueDb((d) => markOutboxSynced(d, id, userId))
}

export async function outboxMarkFailed(id, error, userId) {
  return enqueueDb((d) => markOutboxFailed(d, id, error, userId))
}

export async function outboxDelete(id, userId) {
  return enqueueDb((d) => deleteOutboxRow(d, id, userId))
}

/** Drop successfully-synced receipt rows older than `days` to keep the queue tidy. */
export async function outboxPruneSynced(userId, days = 7) {
  return enqueueDb((d) => pruneOutbox(d, userId, days))
}

export async function cacheTasks(tasks) {
  return enqueueDb(async (d) => {
    await d.withTransactionAsync(async () => {
      for (const t of tasks) {
        await d.runAsync(
          'INSERT OR REPLACE INTO cached_tasks (id, json) VALUES (?, ?)',
          t.id,
          JSON.stringify(t),
        )
      }
    })
  })
}

export async function getCachedTasks() {
  return enqueueDb(async (d) => {
    const rows = await d.getAllAsync('SELECT json FROM cached_tasks')
    return rows.map((r) => JSON.parse(r.json))
  })
}

export async function clearCachedTasks() {
  return enqueueDb((d) => d.runAsync('DELETE FROM cached_tasks'))
}

export async function clearReadCache(userId) {
  return enqueueDb((d) => userId == null
    ? d.runAsync('DELETE FROM read_cache')
    : d.runAsync('DELETE FROM read_cache WHERE user_id = ?', String(userId)))
}

export async function deleteReadCache(module, cacheKey, userId) {
  if (!OFFLINE_CACHE_MODULES.has(module)) throw new Error(`Offline cache module is not approved: ${module}`)
  return enqueueDb((d) => d.runAsync(
    'DELETE FROM read_cache WHERE module = ? AND cache_key = ? AND user_id = ?',
    module,
    cacheKey,
    String(userId),
  ))
}

export async function writeReadCache(module, cacheKey, userId, payload, syncedAt = new Date().toISOString()) {
  if (!OFFLINE_CACHE_MODULES.has(module)) throw new Error(`Offline cache module is not approved: ${module}`)
  if (module === 'notices' && Array.isArray(payload) && payload.some((item) => String(item?.type || '').toLowerCase().includes('payment'))) {
    throw new Error('Payment notices must not be persisted in offline read storage.')
  }
  if (module === 'tasks' && Array.isArray(payload)) {
    payload = payload.map((task) => {
      const safeTask = { ...(task || {}) }
      delete safeTask.assigned_by
      if (!safeTask.property_bill) return safeTask
      const bill = Object.fromEntries(OFFLINE_TASK_BILL_FIELDS
        .filter((field) => Object.prototype.hasOwnProperty.call(safeTask.property_bill, field))
        .map((field) => [field, safeTask.property_bill[field]]))
      safeTask.property_bill = bill
      return safeTask
    })
  }

  return enqueueDb((d) => d.runAsync(
    'INSERT OR REPLACE INTO read_cache (module, cache_key, user_id, payload, synced_at) VALUES (?, ?, ?, ?, ?)',
    module,
    cacheKey,
    String(userId),
    JSON.stringify(payload),
    syncedAt,
  ))
}

export async function readReadCache(module, cacheKey, userId) {
  const ttl = OFFLINE_CACHE_TTL_MS[module]
  if (!ttl) throw new Error(`Offline cache module is not approved: ${module}`)

  return enqueueDb(async (d) => {
    const row = await d.getFirstAsync(
      'SELECT payload, synced_at FROM read_cache WHERE module = ? AND cache_key = ? AND user_id = ?',
      module,
      cacheKey,
      String(userId),
    )
    if (!row) return null

    const syncedAt = Date.parse(row.synced_at)
    if (!Number.isFinite(syncedAt)) {
      await d.runAsync(
        'UPDATE read_cache SET payload = NULL WHERE module = ? AND cache_key = ? AND user_id = ?',
        module,
        cacheKey,
        String(userId),
      )
      throw new Error('Offline cache timestamp is invalid; refresh this data while online.')
    }

    const age = Date.now() - syncedAt
    if (age < 0 || age > ttl) {
      await d.runAsync(
        'UPDATE read_cache SET payload = NULL WHERE module = ? AND cache_key = ? AND user_id = ?',
        module,
        cacheKey,
        String(userId),
      )
      return { status: 'expired', syncedAt: row.synced_at }
    }

    if (row.payload == null) return { status: 'expired', syncedAt: row.synced_at }

    try {
      return { status: 'cached', data: JSON.parse(row.payload), syncedAt: row.synced_at }
    } catch (error) {
      await d.runAsync(
        'UPDATE read_cache SET payload = NULL WHERE module = ? AND cache_key = ? AND user_id = ?',
        module,
        cacheKey,
        String(userId),
      )
      throw new Error('Offline cache data is invalid; refresh this data while online.', { cause: error })
    }
  })
}