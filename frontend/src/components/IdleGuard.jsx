import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth'

const IDLE_MS = 8 * 60 * 1000
const COUNTDOWN_MS = 10 * 1000

/**
 * Automatically signs the user out after 8 minutes of inactivity.
 * Once the idle threshold is reached a 10-second countdown overlay appears
 * and any activity (or the "Stay signed in" button) resets the timer.
 */
export default function IdleGuard({ children }) {
  const { logout } = useAuth()
  const last = useRef(Date.now())
  const [left, setLeft] = useState(null)

  useEffect(() => {
    let lastFire = 0
    const onActivity = () => {
      const now = Date.now()
      if (now - lastFire < 1000) return
      lastFire = now
      last.current = Date.now()
      setLeft(null)
    }
    const evts = ['pointerdown', 'keydown', 'pointermove', 'touchstart', 'scroll', 'focus']
    evts.forEach((e) => window.addEventListener(e, onActivity, { passive: true }))

    const iv = setInterval(() => {
      const idle = Date.now() - last.current
      if (idle >= IDLE_MS) {
        const remaining = IDLE_MS + COUNTDOWN_MS - idle
        if (remaining <= 0) {
          logout()
          return
        }
        setLeft(remaining)
      } else if (idle < IDLE_MS) {
        setLeft(null)
      }
    }, 250)

    return () => {
      evts.forEach((e) => window.removeEventListener(e, onActivity))
      clearInterval(iv)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const secs = left === null ? 0 : Math.ceil(left / 1000)

  return (
    <>
      {children}
      {left !== null && (
        <div className="fixed inset-0 z-[100] grid place-items-center bg-slate-900/60 backdrop-blur-sm px-4">
          <div className="w-full max-w-sm bg-white rounded-2xl border border-slate-200 p-8 text-center shadow-xl">
            <div className="text-4xl mb-3">⏳</div>
            <h2 className="text-xl font-bold text-navy-800">Session timeout</h2>
            <p className="text-sm text-slate-500 mt-2">
              You have been inactive for 8 minutes. Logging out in{' '}
              <b>{secs}</b> {secs === 1 ? 'second' : 'seconds'}.
            </p>
            <button
              onClick={() => { last.current = Date.now(); setLeft(null) }}
              className="mt-6 w-full py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition">
              Stay signed in
            </button>
          </div>
        </div>
      )}
    </>
  )
}