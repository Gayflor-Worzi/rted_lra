import { createContext, useContext, useEffect, useState, useCallback, useRef } from 'react'
import NetInfo from '@react-native-community/netinfo'
import api from './api'
import {
  getDb,
  outboxById,
  outboxAll,
  outboxUnsolved,
  outboxStatusCounts,
  outboxMarkSyncing,
  outboxMarkPending,
  outboxMarkSynced,
  outboxMarkFailed,
  outboxPruneSynced,
  enqueue,
  enqueueBundle,
  enqueueDependent,
  kvGet,
  kvSet,
} from './db'
import { hasSyncIdentity } from './outboxStore.mjs'
import { useAuth } from './auth'

const SyncCtx = createContext(null)

// Module-level re-entry lock. React state (`syncing`) lags a beat behind the
// actual loop, so two rapid processOutbox calls could otherwise both pass the
// `if (syncing) return` guard and interleave. Combined with the serialized DB
// mutex in db.js this guarantees a single outbox flush at a time.
let flushing = false

const KIND_URL = {
  visit: () => '/enforcement-visits',
  discovery: () => '/discoveries',
  valuation: () => '/valuations',
  receipt: () => '/enforcement/submit-receipt',
  action: (payload) => `/enforcement-assignments/${payload.assignment_id}/action`,
  evidence: () => '/evidence/photos',
  gps_certify: () => '/gps/certify',
  engagement: (payload) => `/tasks/${payload.task_id}/engagements`,
  notification_read: (payload) => `/notifications/${payload.notification_id}/read`,
  notification_read_all: () => '/notifications/read-all',
  bill_amendment: (payload) => `/property-bills/${payload.bill_id}/amend`,
}

// Rows stuck mid-flush for longer than this (a crash right after a row was
// marked syncing, before its response could be written) are treated as
// never-sent and revived instead of being skipped forever.
const STALE_SYNCING_MS = 5 * 60 * 1000

function syncingIsStale(updatedAt) {
  if (!updatedAt) return true
  const t = Date.parse(String(updatedAt).replace(' ', 'T') + (String(updatedAt).endsWith('Z') ? '' : 'Z'))
  if (Number.isNaN(t)) return true
  return Date.now() - t > STALE_SYNCING_MS
}

/**
 * Push one queued row to the server. Returns { ok } on success (including a
 * server-side idempotent replay) or { ok:false, offline, message } on failure.
 * Failed rows are kept locally with attempts + last_error so retries are
 * possible and data is never silently lost.
 */
async function attemptRow(row, ownerUserId, authToken, isCurrentSession) {
  if (String(row.owner_user_id) !== String(ownerUserId)) {
    return { ok: false, offline: false, message: 'Submission belongs to a different account and was not sent.' }
  }
  if (!isCurrentSession()) {
    return { ok: false, offline: false, message: 'Account changed; submission was not sent.' }
  }

  let payload = {}
  try {
    payload = JSON.parse(row.payload)
  } catch {}

  // Children (evidence photos) must wait for their parent's server id. Defer
  // without touching retry counts — the next flush resolves the parent first.
  if (row.parent_transaction_id) {
    const serverId = await kvGet(`svc:${ownerUserId}:${row.parent_transaction_id}`)
    if (!serverId) {
      await outboxMarkPending(row.id, ownerUserId)
      return { ok: true, deferred: true }
    }
    payload[payload._parent_field || 'discovery_id'] = Number(serverId)
    delete payload._parent_field
  }

  const attempts = (row.attempts || 0) + 1
  if (!(await outboxMarkSyncing(row.id, attempts, ownerUserId))) {
    return { ok: false, offline: false, message: 'Submission is no longer available for this account.' }
  }
  if (!isCurrentSession()) {
    await outboxMarkPending(row.id, ownerUserId)
    return { ok: false, offline: false, message: 'Account changed; submission was not sent.' }
  }

  const url = KIND_URL[row.kind] ? KIND_URL[row.kind](payload) : null
  if (row.kind === 'action') {
    const message = 'Workflow and assignment decisions require live server authorization and were not replayed offline.'
    await outboxMarkFailed(row.id, message, ownerUserId)
    return { ok: false, offline: false, message }
  }
  if (!url) {
    await outboxMarkFailed(row.id, `Unknown sync kind: ${row.kind}`, ownerUserId)
    // Terminal for this row, but NOT a transport failure — the queue must keep
    // flushing so a single unrecognized kind can never stall later records.
    return { ok: false, offline: false, message: `Unknown sync kind: ${row.kind}` }
  }

  try {
    const res = await api.post(url, payload, {
      headers: { Authorization: `Bearer ${authToken}` },
      authUserId: ownerUserId,
    })
    const serverId = res?.data?.data?.id
    if (['discovery', 'valuation', 'visit'].includes(row.kind) && serverId) {
      await kvSet(`svc:${ownerUserId}:${row.local_transaction_id}`, String(serverId))
    }
    await outboxMarkSynced(row.id, ownerUserId, serverId, res?.data?.data || null)
    return { ok: true }
  } catch (err) {
    const message = err?.response?.data?.message || err?.message || 'Sync failed'
    const status = err?.response?.status
    await outboxMarkFailed(row.id, message, ownerUserId)
    // A server rejection (validation/business rule) is terminal for this row but
    // the rest of the queue should still flush. A transport failure means the
    // device is offline — stop the loop rather than hammering the network.
    return { ok: false, offline: !status, message }
  }
}

export function SyncProvider({ children }) {
  const { user, token, sessionValidated, sessionVersion, isSessionCurrent, validateSession } = useAuth()
  const userId = user?.id == null ? null : String(user.id)
  const sessionRef = useRef(null)
  sessionRef.current = { userId, token, sessionVersion }
  const [countsState, setCountsState] = useState({ userId: null, counts: { pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 } })
  const [syncing, setSyncing] = useState(false)

  const refreshCounts = useCallback(async () => {
    if (userId == null) {
      setCountsState({ userId: null, counts: { pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 } })
      return
    }
    await getDb()
    setCountsState({ userId, counts: await outboxStatusCounts(userId) })
  }, [userId])

  useEffect(() => {
    refreshCounts()
    if (userId != null) outboxPruneSynced(userId, 30)
  }, [refreshCounts, userId])

  const processOutbox = useCallback(async ({ quiet } = {}) => {
    if (flushing) return
    const accountId = userId
    const accountToken = token
    const accountVersion = sessionVersion
    if (!hasSyncIdentity(accountId, accountToken)) {
      return { ok: false, offline: false, message: 'An authenticated account is required to synchronize submissions.' }
    }
    const isCurrentSession = () => {
      const current = sessionRef.current
      return isSessionCurrent(accountId, accountToken, accountVersion)
        && current?.userId === accountId
        && current?.token === accountToken
        && current?.sessionVersion === accountVersion
    }
    flushing = true
    setSyncing(true)
    let result = { ok: true }
    try {
      const network = await NetInfo.fetch()
      if (network.isConnected !== true || network.isInternetReachable === false) {
        return { ok: false, offline: true, message: 'Device is offline.' }
      }
      if (!sessionValidated && !(await validateSession())) {
        return { ok: false, offline: false, message: 'Session could not be revalidated; queued records were not sent.' }
      }
      if (!isCurrentSession()) {
        return { ok: false, offline: false, message: 'Account changed; queued records were not sent.' }
      }
      const rows = await outboxUnsolved(accountId)
      for (const row of rows) {
        if (!isCurrentSession()) {
          result = { ok: false, offline: false, message: 'Account changed; queued records were not sent.' }
          break
        }
        if (row.status === 'syncing') {
          if (!syncingIsStale(row.updated_at)) continue
          // Marooned mid-flight (app/device died between marking syncing and the
          // response). Revive so the next flush gives it another try.
          await outboxMarkPending(row.id, accountId)
        }
        const res = await attemptRow(row, accountId, accountToken, isCurrentSession)
        if (!res.ok) {
          result = res
          if (res.offline || !isCurrentSession()) break
        }
      }
    } finally {
      flushing = false
      setSyncing(false)
      await refreshCounts()
    }
    if (result.ok && !quiet) return { ok: true }
    return result
  }, [isSessionCurrent, refreshCounts, sessionValidated, sessionVersion, token, userId, validateSession])

  useEffect(() => {
    const unsub = NetInfo.addEventListener((state) => {
      if (state.isConnected) processOutbox({ quiet: true })
    })
    return unsub
  }, [processOutbox])

  /** Push a single row (per-record "Sync Now" / retry) without touching the rest. */
  const retryRow = useCallback(async (id) => {
    if (flushing) { await refreshCounts(); return { ok: false, message: 'Sync already in progress.' } }
    const accountId = userId
    const accountToken = token
    const accountVersion = sessionVersion
    if (!hasSyncIdentity(accountId, accountToken)) {
      return { ok: false, message: 'An authenticated account is required to synchronize submissions.' }
    }
    const isCurrentSession = () => {
      const current = sessionRef.current
      return isSessionCurrent(accountId, accountToken, accountVersion)
        && current?.userId === accountId
        && current?.token === accountToken
        && current?.sessionVersion === accountVersion
    }
    flushing = true
    setSyncing(true)
    try {
      const network = await NetInfo.fetch()
      if (network.isConnected !== true || network.isInternetReachable === false) {
        return { ok: false, offline: true, message: 'Device is offline.' }
      }
      if (!sessionValidated && !(await validateSession())) {
        return { ok: false, offline: false, message: 'Session could not be revalidated; queued records were not sent.' }
      }
      if (!isCurrentSession()) return { ok: false, message: 'Account changed; queued record was not sent.' }
      const row = await outboxById(id, accountId)
      if (!row) return { ok: false, message: 'Record no longer queued.' }
      return await attemptRow(row, accountId, accountToken, isCurrentSession)
    } finally {
      flushing = false
      setSyncing(false)
      await refreshCounts()
    }
  }, [isSessionCurrent, refreshCounts, sessionValidated, sessionVersion, token, userId, validateSession])

  const queueAndFlush = async (kind, payload) => {
    if (userId == null) throw new Error('Sign in before saving an offline submission.')
    await getDb()
    const localTransactionId = await enqueue(userId, kind, payload)
    await refreshCounts()
    const net = await NetInfo.fetch()
    const syncResult = net.isConnected
      ? await processOutbox({ quiet: true })
      : { ok: false, offline: true }
    const rows = await outboxAll(userId)
    const row = rows.find((item) => item.local_transaction_id === localTransactionId)
    let serverResponse = null
    try { serverResponse = row?.server_response_json ? JSON.parse(row.server_response_json) : null } catch {}
    return {
      localTransactionId,
      outboxId: row?.id ?? null,
      status: row?.status ?? 'pending',
      serverEntityId: row?.server_entity_id ?? null,
      serverResponse,
      lastError: row?.last_error ?? null,
      syncResult,
    }
  }

  const queueBundleAndFlush = async (kind, payload, children = []) => {
    if (userId == null) throw new Error('Sign in before saving an offline submission.')
    await getDb()
    const localTransactionId = await enqueueBundle(userId, kind, payload, children)
    await refreshCounts()
    const net = await NetInfo.fetch()
    const syncResult = net.isConnected
      ? await processOutbox({ quiet: true })
      : { ok: false, offline: true }
    const rows = await outboxAll(userId)
    const relatedRows = rows.filter((item) =>
      item.local_transaction_id === localTransactionId || item.parent_transaction_id === localTransactionId,
    )
    const row = relatedRows.find((item) => item.local_transaction_id === localTransactionId)
    let serverResponse = null
    try { serverResponse = row?.server_response_json ? JSON.parse(row.server_response_json) : null } catch {}
    return {
      localTransactionId,
      outboxId: row?.id ?? null,
      status: row?.status ?? 'pending',
      serverEntityId: row?.server_entity_id ?? null,
      serverResponse,
      allSynced: relatedRows.length === children.length + 1 && relatedRows.every((item) => item.status === 'synced'),
      lastError: relatedRows.find((item) => item.last_error)?.last_error ?? null,
      syncResult,
    }
  }

  const enqueueForUser = useCallback((kind, payload) => {
    if (userId == null) throw new Error('Sign in before saving an offline submission.')
    return enqueue(userId, kind, payload)
  }, [userId])
  const enqueueDependentForUser = useCallback((kind, payload, parentTransactionId) => {
    if (userId == null) throw new Error('Sign in before saving an offline submission.')
    return enqueueDependent(userId, kind, payload, parentTransactionId)
  }, [userId])
  const counts = countsState.userId === userId
    ? countsState.counts
    : { pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 }

  return (
    <SyncCtx.Provider value={{ counts, pending: counts.pending + counts.syncing + counts.failed, syncing, processOutbox, retryRow, queueAndFlush, queueBundleAndFlush, enqueue: enqueueForUser, enqueueDependent: enqueueDependentForUser }}>
      {children}
    </SyncCtx.Provider>
  )
}

export const useSync = () => useContext(SyncCtx)