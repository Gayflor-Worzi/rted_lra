import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * The web app's dialogs are plain React markup rather than a single shared
 * component. This controller adds one control to the topmost dialog without
 * touching its form state, validation or submit handlers.
 */

const MAX_ATTR = 'data-retd-dialog-maximized'
const MAX_CSS = `
[${MAX_ATTR}="1"] {
  position: fixed !important;
  inset: 8px !important;
  width: auto !important;
  height: auto !important;
  max-width: none !important;
  max-height: none !important;
  overflow: auto !important;
  overscroll-behavior: contain;
}
@media (min-width: 640px) {
  [${MAX_ATTR}="1"] { inset: 16px !important; }
}`

function isRendered(el) {
  const cs = window.getComputedStyle(el)
  if (cs.display === 'none' || cs.visibility === 'hidden' || (cs.opacity !== '' && Number(cs.opacity) === 0)) return false
  const r = el.getBoundingClientRect()
  return r.width > 0 && r.height > 0
}

/** Full-viewport fixed layers (dialogs, drawers, photo viewers). */
function fullScreenOverlays() {
  return Array.from(document.querySelectorAll('div.fixed, section.fixed, aside.fixed')).filter((el) => {
    if (!isRendered(el)) return false
    if (window.getComputedStyle(el).position !== 'fixed') return false
    const r = el.getBoundingClientRect()
    const viewport = document.documentElement
    return r.width >= viewport.clientWidth - 4 && r.height >= viewport.clientHeight - 4
  })
}

/** The overlay painted last / highest wins, so a photo viewer hides the control. */
function topOverlay() {
  const overlays = fullScreenOverlays()
  let best = null
  let bestZ = -Infinity
  for (const el of overlays) {
    const z = Number.parseInt(window.getComputedStyle(el).zIndex, 10)
    const level = Number.isFinite(z) ? z : 0
    if (level >= bestZ) {
      best = el
      bestZ = level
    }
  }
  return best
}

/** The largest white surface inside the overlay is the dialog panel. */
function dialogPanel(overlay) {
  if (!overlay) return null
  const candidates = Array.from(overlay.querySelectorAll('div, form, section')).filter((el) => {
    if (!isRendered(el)) return false
    if (window.getComputedStyle(el).backgroundColor !== 'rgb(255, 255, 255)') return false
    const r = el.getBoundingClientRect()
    return r.width >= 280 && r.height >= 120
  })
  return candidates.reduce((largest, el) => {
    if (!largest) return el
    const r = el.getBoundingClientRect()
    const best = largest.getBoundingClientRect()
    return r.width * r.height > best.width * best.height ? el : largest
  }, null)
}

export default function DialogMaximize() {
  const panelRef = useRef(null)
  const frameRef = useRef(0)
  const maximizedPanels = useRef(new WeakMap())
  const [panel, setPanel] = useState(null)
  const [maximized, setMaximized] = useState(false)
  const [anchor, setAnchor] = useState(null)

  const scan = useCallback(() => {
    const next = dialogPanel(topOverlay())
    const prev = panelRef.current
    if (prev !== next) {
      panelRef.current = next
      setPanel(next)
      setMaximized(next ? maximizedPanels.current.get(next) === true : false)
    }
    if (next) {
      const rect = next.getBoundingClientRect()
      setAnchor({
        left: Math.max(8, rect.right - 84),
        top: Math.max(8, rect.top + 8),
      })
    } else {
      setAnchor(null)
    }
  }, [])

  useEffect(() => {
    const schedule = () => {
      if (frameRef.current) return
      frameRef.current = window.requestAnimationFrame(() => {
        frameRef.current = 0
        scan()
      })
    }
    const observer = new MutationObserver(schedule)
    observer.observe(document.body, { childList: true, subtree: true })
    window.addEventListener('resize', schedule)
    schedule()
    return () => {
      observer.disconnect()
      window.removeEventListener('resize', schedule)
      if (frameRef.current) window.cancelAnimationFrame(frameRef.current)
      if (panelRef.current) panelRef.current.removeAttribute(MAX_ATTR)
    }
  }, [scan])

  const toggle = () => {
    const el = panelRef.current
    if (!el || !el.isConnected) return
    if (maximized) {
      el.removeAttribute(MAX_ATTR)
      maximizedPanels.current.set(el, false)
      setMaximized(false)
    } else {
      el.setAttribute(MAX_ATTR, '1')
      maximizedPanels.current.set(el, true)
      setMaximized(true)
    }
    const scrollTop = el.scrollTop
    window.requestAnimationFrame(() => {
      el.scrollTop = scrollTop
      scan()
    })
  }

  if (!panel || !anchor) return null

  return (
    <>
      <style>{MAX_CSS}</style>
      <button
        type="button"
        onClick={toggle}
        aria-pressed={maximized}
        aria-label={maximized ? 'Restore dialog size' : 'Maximize dialog'}
        title={maximized ? 'Restore dialog size' : 'Maximize dialog'}
        style={{ position: 'fixed', left: anchor.left, top: anchor.top }}
        className="z-[400] inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-navy-800 shadow-lg hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
      >
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          {maximized
            ? <path d="M7 3v4H3M13 17v-4h4M7 7 3 3m10 10 4 4" />
            : <path d="M3 7V3h4M17 13v4h-4M3 3l5 5m9-5-5 5m5 4-5 5m-4-5-5 5" />}
        </svg>
      </button>
    </>
  )
}
