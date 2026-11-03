function requireOwner(userId) {
  if (userId === null || userId === undefined || String(userId).trim() === '') {
    throw new Error('An authenticated account is required to access offline drafts.')
  }
  return String(userId)
}

export async function saveDraft(db, userId, draftKey, payload) {
  const ownerId = requireOwner(userId)
  if (!draftKey || String(draftKey).trim() === '') throw new Error('An offline draft key is required.')
  const now = new Date().toISOString()
  return db.runAsync(
    `INSERT INTO offline_drafts (owner_user_id, draft_key, payload, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?)
     ON CONFLICT (owner_user_id, draft_key) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at`,
    ownerId,
    String(draftKey),
    JSON.stringify(payload),
    now,
    now,
  )
}

export async function getDraft(db, userId, draftKey) {
  const ownerId = requireOwner(userId)
  const row = await db.getFirstAsync(
    'SELECT payload, created_at, updated_at FROM offline_drafts WHERE owner_user_id = ? AND draft_key = ?',
    ownerId,
    String(draftKey),
  )
  if (!row) return null
  try {
    return { ...JSON.parse(row.payload), created_at: row.created_at, updated_at: row.updated_at }
  } catch (error) {
    throw new Error('Offline draft data is invalid and was preserved for recovery.', { cause: error })
  }
}

export async function listDrafts(db, userId) {
  const ownerId = requireOwner(userId)
  const rows = await db.getAllAsync(
    'SELECT draft_key, payload, created_at, updated_at FROM offline_drafts WHERE owner_user_id = ? ORDER BY updated_at DESC',
    ownerId,
  )
  return rows.map((row) => {
    try {
      return { ...JSON.parse(row.payload), draft_key: row.draft_key, created_at: row.created_at, updated_at: row.updated_at }
    } catch (error) {
      throw new Error(`Offline draft "${row.draft_key}" is invalid and was preserved for recovery.`, { cause: error })
    }
  })
}

export async function deleteDraft(db, userId, draftKey) {
  const ownerId = requireOwner(userId)
  const result = await db.runAsync(
    'DELETE FROM offline_drafts WHERE owner_user_id = ? AND draft_key = ?',
    ownerId,
    String(draftKey),
  )
  return result.changes > 0
}
