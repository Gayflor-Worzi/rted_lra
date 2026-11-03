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
  insertOutboxBundle,
  markOutboxFailed,
  markOutboxPending,
  markOutboxSyncing,
  markOutboxSynced,
  migrateOutboxOwnership,
  pruneOutbox,
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
        server_entity_id TEXT,
        server_response_json TEXT,
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
      withTransactionAsync: async (callback) => {
        connection.exec('BEGIN')
        try {
          const result = await callback()
          connection.exec('COMMIT')
          return result
        } catch (error) {
          connection.exec('ROLLBACK')
          throw error
        }
      },
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

  it('records server confirmation without pruning unsynchronized submissions', async () => {
    await insertOutbox(db, 'user-a', 'receipt', { receipt_number: 'REC-1' }, '', 'TX-RECEIPT')
    await insertOutbox(db, 'user-a', 'visit', { notes: 'pending visit' }, '', 'TX-VISIT')

    assert.equal(await markOutboxSynced(db, 1, 'user-a', 42, { id: 42, status: 'Pending' }), true)
    const submitted = await getOutboxRow(db, 1, 'user-a')
    assert.equal(submitted.status, 'synced')
    assert.equal(submitted.server_entity_id, '42')
    assert.deepEqual(JSON.parse(submitted.server_response_json), { id: 42, status: 'Pending' })

    await db.runAsync("UPDATE outbox SET synced_at = datetime('now', '-31 days') WHERE id = ?", 1)
    await db.runAsync("UPDATE outbox SET created_at = datetime('now', '-31 days') WHERE id = ?", 2)
    await pruneOutbox(db, 'user-a', 30)

    assert.equal(await getOutboxRow(db, 1, 'user-a'), null)
    assert.equal((await getOutboxRow(db, 2, 'user-a')).status, 'pending')
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

  it('queues a valuation and dependent evidence atomically for one account', async () => {
    await insertOutboxBundle(db, 'user-a', 'valuation', { owner_name: 'Offline owner' }, [{
      kind: 'evidence',
      parentField: 'valuation_id',
      payload: { data_uri: 'data:image/jpeg;base64,ZmFrZQ==' },
    }], 'TX-VALUATION', () => 'TX-EVIDENCE')

    const rows = await getOutboxRows(db, 'user-a')
    assert.equal(rows.length, 2)
    assert.equal(rows[0].kind, 'valuation')
    assert.equal(rows[1].kind, 'evidence')
    assert.equal(rows[1].parent_transaction_id, 'TX-VALUATION')
    assert.equal(JSON.parse(rows[1].payload)._parent_field, 'valuation_id')
    assert.equal(rows.every((row) => row.owner_user_id === 'user-a'), true)
  })

  it('rolls back the entire offline bundle if a child cannot be queued', async () => {
    await assert.rejects(insertOutboxBundle(db, 'user-a', 'valuation', { owner_name: 'Offline owner' }, [{
      kind: 'evidence',
      parentField: 'valuation_id',
      payload: { data_uri: 'data:image/jpeg;base64,ZmFrZQ==' },
    }], 'TX-ROLLBACK', () => { throw new Error('transaction id unavailable') }))

    assert.deepEqual(await getOutboxRows(db, 'user-a'), [])
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
