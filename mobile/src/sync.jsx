import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import NetInfo from '@react-native-community/netinfo'
import api from './api'
import {
  getDb,
  outboxUnsolved,
  outboxStatusCounts,
  outboxMarkSyncing,
  outboxMarkSynced,
  outboxMarkFailed,
  outboxPruneSynced,
  enqueue,
} from './db'

const SyncCtx = createContext(null)

const KIND_URL = {
  visit: () => '/enforcement-visits',
  discovery: () => '/discoveries',
  receipt: () => '/enforcement/submit-receipt',
  action: (payload) => `/enforcement-assignments/${payload.assignment_id}/action`,
}

/**
 * Push one queued row to the server. Returns { ok } on success (including a
 * server-side idempotent replay) or { ok:false, offline, message } on failure.
 * Failed rows are kept locally with attempts + last_error so retries are
 * possible and data is never silently lost.
 */
async function attemptRow(row) {
  let payload = {}
  try {
    payload = JSON.parse(row.payload)
  } catch {}

  const attempts = (row.attempts || 0) + 1
  await outboxMarkSyncing(row.id, attempts)

  const url = KIND_URL[row.kind] ? KIND_URL[row.kind](payload) : null
  if (!url) {
    await outboxMarkFailed(row.id, `Unknown sync kind: ${row.kind}`)
    return { ok: false, offline: true, message: `Unknown sync kind: ${row.kind}` }
  }

  try {
    await api.post(url, payload)
    await outboxMarkSynced(row.id)
    return { ok: true }
  } catch (err) {
    const message = err?.response?.data?.message || err?.message || 'Sync failed'
    const status = err?.response?.status
    await outboxMarkFailed(row.id, message)
    // A server rejection (validation/business rule) is terminal for this row but
    // the rest of the queue should still flush. A transport failure means the
    // device is offline — stop the loop rather than hammering the network.
    return { ok: false, offline: !status, message }
  }
}

export function SyncProvider({ children }) {
  const [counts, setCounts] = useState({ pending: 0, syncing: 0, synced: 0, failed: 0, total: 0 })
  const [syncing, setSyncing] = useState(false)

  const refreshCounts = useCallback(async () => {
    await getDb()
    setCounts(await outboxStatusCounts())
  }, [])

  useEffect(() => { refreshCounts(); outboxPruneSynced(7) }, [refreshCounts])

  useEffect(() => {
    const unsub = NetInfo.addEventListener((state) => {
      if (state.isConnected && !syncing) processOutbox({ quiet: true })
    })
    return unsub
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [syncing])

  const processOutbox = useCallback(async ({ quiet } = {}) => {
    if (syncing) return
    setSyncing(true)
    let result = { ok: true }
    try {
      const rows = await outboxUnsolved()
      for (const row of rows) {
        if (row.status === 'syncing') continue
        const res = await attemptRow(row)
        if (!res.ok) {
          result = res
          if (res.offline) break
        }
      }
    } finally {
      setSyncing(false)
      await refreshCounts()
    }
    if (result.ok && !quiet) return { ok: true }
    return result
  }, [syncing, refreshCounts])

  /** Push a single row (per-record "Sync Now" / retry) without touching the rest. */
  const retryRow = useCallback(async (id) => {
    const rows = await outboxUnsolved()
    const row = rows.find((r) => r.id === id) || (await (await getDb()).getAllAsync('SELECT * FROM outbox WHERE id = ?', id))[0]
    if (!row) { await refreshCounts(); return { ok: false, message: 'Record no longer queued.' } }
    setSyncing(true)
    try {
      return await attemptRow(row)
    } finally {
      setSyncing(false)
      await refreshCounts()
    }
  }, [refreshCounts])

  const queueAndFlush = async (kind, payload) => {
    await getDb()
    await enqueue(kind, payload)
    await refreshCounts()
    const net = await NetInfo.fetch()
    if (net.isConnected) await processOutbox({ quiet: true })
  }

  return (
    <SyncCtx.Provider value={{ counts, pending: counts.pending + counts.syncing + counts.failed, syncing, processOutbox, retryRow, queueAndFlush }}>
      {children}
    </SyncCtx.Provider>
  )
}

export const useSync = () => useContext(SyncCtx)