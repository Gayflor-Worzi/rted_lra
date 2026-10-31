import { useState } from 'react'
import api from '../api'
import { useAuth } from '../auth'
import { Btn } from '../ui'

/**
 * Print and download controls for the official Property Record sheet (spec §7/§9).
 *
 * Both actions go through the API client rather than plain links, and that is not a
 * stylistic choice. The session token lives in localStorage and travels in an
 * Authorization header, so a bare href to either endpoint is an unauthenticated
 * request: the browser would receive a 401 and the operator would see a login page
 * where a record should be. Fetching the document first and handing the bytes to
 * the browser directly is what makes these work at all.
 *
 * The permission is checked here for the button's own sake. It is not what protects
 * the record - the server re-checks property.export and re-derives record scope on
 * every request - so a user who somehow reached this control without the grant gets
 * a refusal from the server, not a document.
 *
 * @param {{ recordType: 'discovery' | 'valuation', recordId: number, label?: string }} props
 */
export default function PropertyRecordExport({ recordType, recordId, label = 'record' }) {
  const { can } = useAuth()
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState('')

  if (!can('property.export') || !recordId) return null

  const endpoint = (kind) => `/property-record/${recordType}/${recordId}/${kind}`

  /** The server names the file; a generic one would lose the record reference. */
  const filenameFrom = (res, fallback) => {
    const disposition = res?.headers?.['content-disposition'] || ''
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
    return match ? decodeURIComponent(match[1].trim()) : fallback
  }

  const download = async () => {
    setBusy('pdf')
    setError('')
    try {
      const res = await api.get(endpoint('pdf'), { responseType: 'blob' })
      // A blob URL is a live handle on the bytes, so it has to be released or the
      // document stays in memory for as long as the tab is open.
      const href = URL.createObjectURL(res.data)
      try {
        const a = document.createElement('a')
        a.href = href
        a.download = filenameFrom(res, `${label}.pdf`)
        document.body.appendChild(a)
        a.click()
        a.remove()
      } finally {
        URL.revokeObjectURL(href)
      }
    } catch (e) {
      setError('The record could not be downloaded. Please try again.')
    } finally {
      setBusy(null)
    }
  }

  /**
   * Open the print window before the request, not after it.
   *
   * Popup blockers only permit window.open while a user gesture is still on the
   * stack, and awaiting the response drops that context. So the window is claimed
   * synchronously, shows a waiting notice, and is filled in once the sheet
   * arrives - which also means the operator sees something honest straight away
   * instead of a blank tab that looks broken.
   */
  const print = async () => {
    setError('')
    const win = window.open('', '_blank')
    if (!win) {
      setError('Your browser blocked the print window. Allow pop-ups for this site, then try again.')
      return
    }

    win.document.write(
      '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preparing record…</title>' +
        '<style>body{margin:0;font:14px/1.6 system-ui,sans-serif;color:#334155;' +
        'display:grid;place-items:center;height:100vh;background:#f1f5f9;text-align:center;padding:24px}</style>' +
        '</head><body><div><strong>Preparing the property record…</strong><br>' +
        'This can take a moment on a large record.</div></body></html>'
    )
    win.document.close()

    setBusy('print')
    try {
      const res = await api.get(endpoint('print'), { responseType: 'text' })
      const html = res.data

      // The sheet is written into the window we already hold. It is a same-origin
      // about:blank document, so the view's own inline handlers still run and its
      // Print button remains available.
      win.document.open()
      win.document.write(html)
      win.document.close()

      // Raise the print dialogue once the sheet is actually laid out. Doing it too
      // early prints a blank page, and the view's own button covers the case where
      // the operator dismisses this dialogue.
      const go = () => {
        win.focus()
        try { win.print() } catch { /* the toolbar's Print button is the fallback */ }
      }
      if (win.document.readyState === 'complete') go()
      else win.addEventListener('load', go, { once: true })
    } catch (e) {
      win.close()
      setError('The record could not be prepared for printing. Please try again.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={print} disabled={!!busy}>
        {busy === 'print' ? 'Preparing…' : '🖨 Print'}
      </Btn>
      <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={download} disabled={!!busy}>
        {busy === 'pdf' ? 'Downloading…' : '⬇ PDF'}
      </Btn>
      {error && <span className="text-[11px] text-red-600 max-w-[16rem] text-right">{error}</span>}
    </>
  )
}
