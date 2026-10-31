function requireOwner(userId) {
  if (userId === null || userId === undefined || String(userId).trim() === '') {
    throw new Error('An authenticated account is required to access offline submissions.')
  }
  return String(userId)
}

export async function migrateOutboxOwnership(db) {
  const columns = await db.getAllAsync("PRAGMA table_info('outbox')")
  if (!columns.some((column) => column.name === 'owner_user_id')) {
    await db.execAsync('ALTER TABLE outbox ADD COLUMN owner_user_id TEXT')
  }
  await db.execAsync('CREATE INDEX IF NOT EXISTS outbox_owner_status_idx ON outbox (owner_user_id, status, id)')
}

export function hasSyncIdentity(userId, token) {
  return userId !== null
    && userId !== undefined
    && String(userId).trim() !== ''
    && typeof token === 'string'
    && token.length > 0
}

export async function insertOutbox(db, userId, kind, payload, parentTransactionId, localTransactionId) {
  const ownerId = requireOwner(userId)
  if (parentTransactionId) {
    const parent = await db.getFirstAsync(
      'SELECT id FROM outbox WHERE local_transaction_id = ? AND owner_user_id = ?',
      parentTransactionId,
      ownerId,
    )
    if (!parent) throw new Error('The parent submission is not available for this account.')
  }

  const body = { ...payload, local_transaction_id: localTransactionId }
  return db.runAsync(
    'INSERT INTO outbox (kind, payload, status, local_transaction_id, parent_transaction_id, owner_user_id) VALUES (?, ?, ?, ?, ?, ?)',
    kind,
    JSON.stringify(body),
    'pending',
    localTransactionId,
    parentTransactionId,
    ownerId,
  )
}

export async function getOutboxCounts(db, userId) {
  const ownerId = requireOwner(userId)
  const rows = await db.getAllAsync(
    'SELECT status, COUNT(*) as n FROM outbox WHERE owner_user_id = ? GROUP BY status',
    ownerId,
  )
  const counts = { pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 }
  for (const row of rows) {
    if (counts[row.status] !== undefined) counts[row.status] = Number(row.n)
  }
  counts.total = counts.pending + counts.syncing + counts.synced + counts.failed
  return counts
}

export async function getOutboxRows(db, userId, status = null) {
  const ownerId = requireOwner(userId)
  if (status === 'unsolved') {
    return db.getAllAsync(
      "SELECT * FROM outbox WHERE owner_user_id = ? AND status != 'synced' ORDER BY id ASC",
      ownerId,
    )
  }
  if (status === 'failed' || status === 'synced') {
    const order = status === 'synced' ? 'DESC' : 'ASC'
    return db.getAllAsync(
      `SELECT * FROM outbox WHERE owner_user_id = ? AND status = ? ORDER BY id ${order}`,
      ownerId,
      status,
    )
  }
  return db.getAllAsync('SELECT * FROM outbox WHERE owner_user_id = ? ORDER BY id ASC', ownerId)
}

export async function getOutboxRow(db, id, userId) {
  const ownerId = requireOwner(userId)
  return (await db.getFirstAsync(
    'SELECT * FROM outbox WHERE id = ? AND owner_user_id = ?',
    id,
    ownerId,
  )) || null
}

export async function markOutboxPending(db, id, userId) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync(
    "UPDATE outbox SET status = 'pending', updated_at = datetime('now') WHERE id = ? AND owner_user_id = ?",
    id,
    ownerId,
  )
  return result.changes > 0
}

export async function markOutboxSyncing(db, id, attempts, userId) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync(
    "UPDATE outbox SET status = 'syncing', attempts = ?, updated_at = datetime('now') WHERE id = ? AND owner_user_id = ?",
    attempts,
    id,
    ownerId,
  )
  return result.changes > 0
}

export async function markOutboxSynced(db, id, userId) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync(
    "UPDATE outbox SET status = 'synced', last_error = NULL, synced_at = datetime('now'), updated_at = datetime('now') WHERE id = ? AND owner_user_id = ?",
    id,
    ownerId,
  )
  return result.changes > 0
}

export async function markOutboxFailed(db, id, error, userId) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync(
    "UPDATE outbox SET status = 'failed', last_error = ?, updated_at = datetime('now') WHERE id = ? AND owner_user_id = ?",
    String(error ?? 'Unknown error').slice(0, 500),
    id,
    ownerId,
  )
  return result.changes > 0
}

export async function deleteOutboxRow(db, id, userId) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync('DELETE FROM outbox WHERE id = ? AND owner_user_id = ?', id, ownerId)
  return result.changes > 0
}

export async function pruneOutbox(db, userId, days = 7) {
  const ownerId = requireOwner(userId)
  return db.runAsync(
    "DELETE FROM outbox WHERE owner_user_id = ? AND status = 'synced' AND synced_at IS NOT NULL AND datetime(synced_at) < datetime('now', '-' || ? || ' days')",
    ownerId,
    days,
  )
}
