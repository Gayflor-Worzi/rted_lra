import { useCallback, useEffect, useRef, useState } from 'react'
import { getOfflineDraft, saveOfflineDraft, deleteOfflineDraft } from './db'

export function useOfflineDraft(draftKey, userId, value, restore, shouldSave = true) {
  const restoreRef = useRef(restore)
  restoreRef.current = restore
  const serializedValue = JSON.stringify(value)
  const [ready, setReady] = useState(false)
  const [error, setError] = useState('')
  const suppressedValue = useRef(null)

  useEffect(() => {
    let active = true
    setReady(false)
    if (userId == null) {
      setReady(true)
      return () => { active = false }
    }
    getOfflineDraft(userId, draftKey).then((draft) => {
      if (!active) return
      if (draft) restoreRef.current(draft)
      setError('')
      setReady(true)
    }).catch((cause) => {
      if (!active) return
      setError(cause?.message || 'The offline draft could not be loaded.')
      setReady(true)
    })
    return () => { active = false }
  }, [draftKey, userId])

  useEffect(() => {
    if (!ready || userId == null || !shouldSave) return undefined
    const timer = setTimeout(() => {
      if (suppressedValue.current !== null) {
        if (suppressedValue.current === serializedValue) return
        suppressedValue.current = null
      }
      saveOfflineDraft(userId, draftKey, JSON.parse(serializedValue))
        .then(() => setError(''))
        .catch((cause) => setError(cause?.message || 'The offline draft could not be saved.'))
    }, 250)
    return () => clearTimeout(timer)
  }, [draftKey, ready, serializedValue, shouldSave, userId])

  const clear = useCallback(async () => {
    if (userId == null) throw new Error('An authenticated account is required to clear an offline draft.')
    suppressedValue.current = serializedValue
    await deleteOfflineDraft(userId, draftKey)
    setError('')
  }, [draftKey, serializedValue, userId])

  return { ready, error, clear }
}
