import { createContext, useContext, useEffect, useState } from 'react'
import NetInfo from '@react-native-community/netinfo'
import api from './api'
import { getDb, outboxAll, outboxDelete, outboxCount, enqueue } from './db'

const SyncCtx = createContext(null)

export function SyncProvider({ children }) {
  const [pending, setPending] = useState(0)
  const [syncing, setSyncing] = useState(false)

  const refreshCount = async () => {
    await getDb()
    setPending(await outboxCount())
  }

  useEffect(() => { refreshCount() }, [])

  useEffect(() => {
    const unsub = NetInfo.addEventListener((state) => {
      if (state.isConnected && !syncing) processOutbox()
    })
    return unsub
  }, [syncing])

  const processOutbox = async () => {
    if (syncing) return
    setSyncing(true)
    let keepTrying = true
    try {
      const rows = await outboxAll()
      for (const row of rows) {
        if (!keepTrying) break
        const payload = JSON.parse(row.payload)
        try {
          switch (row.kind) {
            case 'visit':
              await api.post('/enforcement-visits', payload)
              break
            case 'discovery':
              await api.post('/discoveries', payload)
              break
            case 'receipt':
              await api.post('/enforcement/submit-receipt', payload)
              break
            case 'action':
              await api.post(`/enforcement-assignments/${payload.assignment_id}/action`, {
                action: payload.action,
                visit_date: payload.visit_date,
                notes: payload.notes,
              })
              break
          }
          // Only remove the row once the server confirmed the write succeeded.
          await outboxDelete(row.id)
        } catch (err) {
          // Network / offline / auth failure — keep everything and stop, so the
          // records are never silently lost. They stay visible in the Offline
          // Queue for the user to inspect or remove manually.
          keepTrying = false
          break
        }
      }
    } finally {
      setSyncing(false)
      await refreshCount()
    }
  }

  const queueAndFlush = async (kind, payload) => {
    await getDb()
    await enqueue(kind, payload)
    await refreshCount()
    const net = await NetInfo.fetch()
    if (net.isConnected) await processOutbox()
  }

  return (
    <SyncCtx.Provider value={{ pending, syncing, processOutbox, queueAndFlush }}>
      {children}
    </SyncCtx.Provider>
  )
}

export const useSync = () => useContext(SyncCtx)
