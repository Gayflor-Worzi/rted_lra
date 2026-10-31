import { afterEach, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { DatabaseSync } from 'node:sqlite'
import {
  deleteOutboxRow,
  getOutboxCounts,
  getOutboxRow,
  getOutboxRows,
  hasSyncIdentity,
  insertOutbox,
  markOutboxFailed,
  markOutboxPending,
  markOutboxSyncing,
  migrateOutboxOwnership,
} from './outboxStore.mjs'

describe('offline outbox account isolation', () => {
  let connection
  let db

  beforeEach(() => {
    connection = new DatabaseSync(':memory:')
    connection.exec(`
      CREATE TABLE outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        payload TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        synced_at TEXT,
        updated_at TEXT,
        local_transaction_id TEXT NOT NULL DEFAULT '',
        parent_transaction_id TEXT NOT NULL DEFAULT '',
        owner_user_id TEXT
      );
    `)
    db = {
      runAsync: async (sql, ...args) => {
        const result = connection.prepare(sql).run(...args)
        return { changes: Number(result.changes) }
      },
      execAsync: async (sql) => connection.exec(sql),
      getFirstAsync: async (sql, ...args) => connection.prepare(sql).get(...args) || null,
      getAllAsync: async (sql, ...args) => connection.prepare(sql).all(...args),
    }
  })

  afterEach(() => connection.close())

  it('keeps A submissions private across logout and B login and restores them to A', async () => {
    await insertOutbox(db, 'user-a', 'discovery', { property_address: 'A property' }, '', 'TX-A')
    const rowA = await getOutboxRow(db, 1, 'user-a')

    assert.equal(await getOutboxRow(db, rowA.id, 'user-b'), null)
    assert.deepEqual(await getOutboxRows(db, 'user-b', 'unsolved'), [])
    assert.deepEqual(await getOutboxCounts(db, 'user-b'), {
      pending: 0, syncing: 0, synced: 0, failed: 0, total: 0,
    })
    assert.equal(await markOutboxFailed(db, rowA.id, 'attempted by B', 'user-b'), false)
    assert.equal(await deleteOutboxRow(db, rowA.id, 'user-b'), false)
    assert.equal((await getOutboxRow(db, rowA.id, 'user-a')).owner_user_id, 'user-a')
  })

  it('retains the owner after a failed attempt and an interrupted-sync retry', async () => {
    await insertOutbox(db, 'user-a', 'visit', { notes: 'field visit' }, '', 'TX-A-2')
    const rowA = await getOutboxRow(db, 1, 'user-a')

    assert.equal(await markOutboxSyncing(db, rowA.id, 1, 'user-a'), true)
    assert.equal(await markOutboxPending(db, rowA.id, 'user-b'), false)
    assert.equal(await markOutboxPending(db, rowA.id, 'user-a'), true)
    assert.equal(await markOutboxFailed(db, rowA.id, 'network unavailable', 'user-a'), true)

    const failed = await getOutboxRow(db, rowA.id, 'user-a')
    assert.equal(failed.status, 'failed')
    assert.equal(failed.owner_user_id, 'user-a')
    assert.equal(failed.attempts, 1)
  })

  it('does not allow unauthenticated access or a dependent submission from another account', async () => {
    await insertOutbox(db, 'user-a', 'discovery', {}, '', 'TX-PARENT')

    assert.equal(hasSyncIdentity(null, null), false)
    assert.equal(hasSyncIdentity('user-a', ''), false)
    assert.equal(hasSyncIdentity('user-a', 'valid-token'), true)
    await assert.rejects(getOutboxRows(db, null, 'unsolved'), /authenticated account is required/i)
    await assert.rejects(
      insertOutbox(db, 'user-b', 'evidence', {}, 'TX-PARENT', 'TX-CHILD'),
      /parent submission is not available for this account/i,
    )
    await insertOutbox(db, 'user-a', 'evidence', {}, 'TX-PARENT', 'TX-CHILD-A')
    assert.equal((await getOutboxRows(db, 'user-a')).length, 2)
    assert.equal((await getOutboxRows(db, 'user-b')).length, 0)
  })

  it('quarantines legacy unowned rows without deleting or transferring them', async () => {
    await db.runAsync(
      "INSERT INTO outbox (kind, payload, status, local_transaction_id, owner_user_id) VALUES (?, ?, 'pending', ?, NULL)",
      'discovery',
      '{}',
      'LEGACY-TX',
    )

    assert.deepEqual(await getOutboxRows(db, 'user-a', 'unsolved'), [])
    assert.deepEqual(await getOutboxRows(db, 'user-b', 'unsolved'), [])
    assert.equal(connection.prepare('SELECT owner_user_id FROM outbox').get().owner_user_id, null)
    assert.equal(connection.prepare('SELECT COUNT(*) AS count FROM outbox').get().count, 1)
  })

  it('adds ownership to an existing outbox without assigning or deleting legacy rows', async () => {
    const legacyConnection = new DatabaseSync(':memory:')
    const legacyDb = {
      execAsync: async (sql) => legacyConnection.exec(sql),
      getAllAsync: async (sql, ...args) => legacyConnection.prepare(sql).all(...args),
    }
    try {
      legacyConnection.exec(`
        CREATE TABLE outbox (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          kind TEXT NOT NULL,
          payload TEXT NOT NULL,
          created_at TEXT,
          status TEXT NOT NULL DEFAULT 'pending',
          attempts INTEGER NOT NULL DEFAULT 0,
          last_error TEXT,
          synced_at TEXT,
          updated_at TEXT,
          local_transaction_id TEXT NOT NULL DEFAULT '',
          parent_transaction_id TEXT NOT NULL DEFAULT ''
        );
        INSERT INTO outbox (kind, payload, local_transaction_id) VALUES ('visit', '{}', 'LEGACY-MIGRATION');
      `)

      await migrateOutboxOwnership(legacyDb)

      const row = legacyConnection.prepare('SELECT * FROM outbox').get()
      assert.equal(row.owner_user_id, null)
      assert.equal(row.local_transaction_id, 'LEGACY-MIGRATION')
      assert.equal(legacyConnection.prepare("SELECT COUNT(*) AS count FROM sqlite_master WHERE type = 'index' AND name = 'outbox_owner_status_idx'").get().count, 1)
    } finally {
      legacyConnection.close()
    }
  })
})
