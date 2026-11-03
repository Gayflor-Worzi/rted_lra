import { afterEach, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { DatabaseSync } from 'node:sqlite'
import { deleteDraft, getDraft, listDrafts, saveDraft } from './offlineDraftStore.mjs'

describe('encrypted offline draft ownership', () => {
  let connection
  let db

  beforeEach(() => {
    connection = new DatabaseSync(':memory:')
    connection.exec(`
      CREATE TABLE offline_drafts (
        owner_user_id TEXT NOT NULL,
        draft_key TEXT NOT NULL,
        payload TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY (owner_user_id, draft_key)
      );
    `)
    db = {
      runAsync: async (sql, ...args) => {
        const result = connection.prepare(sql).run(...args)
        return { changes: Number(result.changes) }
      },
      getFirstAsync: async (sql, ...args) => connection.prepare(sql).get(...args) || null,
      getAllAsync: async (sql, ...args) => connection.prepare(sql).all(...args),
    }
  })

  afterEach(() => connection.close())

  it('keeps drafts account-scoped and preserves them until explicit deletion', async () => {
    await saveDraft(db, 'account-a', 'receipt:task-1', { receipt_number: 'REC-1', photo: 'encrypted-payload' })
    assert.equal(await getDraft(db, 'account-b', 'receipt:task-1'), null)
    assert.equal((await getDraft(db, 'account-a', 'receipt:task-1')).receipt_number, 'REC-1')
    assert.equal((await listDrafts(db, 'account-a')).length, 1)
    assert.equal((await listDrafts(db, 'account-b')).length, 0)
    assert.equal(await deleteDraft(db, 'account-b', 'receipt:task-1'), false)
    assert.ok(await getDraft(db, 'account-a', 'receipt:task-1'))
  })

  it('updates a draft without creating duplicate rows', async () => {
    await saveDraft(db, 'account-a', 'receipt:task-1', { receipt_number: 'REC-1' })
    await saveDraft(db, 'account-a', 'receipt:task-1', { receipt_number: 'REC-2' })
    assert.equal((await listDrafts(db, 'account-a')).length, 1)
    assert.equal((await getDraft(db, 'account-a', 'receipt:task-1')).receipt_number, 'REC-2')
    assert.equal(await deleteDraft(db, 'account-a', 'receipt:task-1'), true)
    assert.equal(await getDraft(db, 'account-a', 'receipt:task-1'), null)
  })
})
