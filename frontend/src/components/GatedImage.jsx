import { useEffect, useState } from 'react'
import api, { apiPath } from '../api'

/**
 * Evidence is authorisation-gated, so an image is never shown from a bare
 * <img src>: the file is fetched with the bearer token and rendered from a blob
 * URL that is revoked when the component unmounts or the source changes.
 *
 * The URL arrives from the API with the `/api/v1` prefix already on it while
 * axios prepends the same baseURL, so the prefix has to be stripped exactly once
 * here or the request 404s behind a perfectly valid token.
 */
export default function GatedImage({ src, alt, className, style, contain }) {
  const [blobUrl, setBlobUrl] = useState(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let revoked = null
    let cancelled = false
    setBlobUrl(null)
    setFailed(false)
    if (!src) return undefined

    const path = apiPath(src)
    const viaApi = !/^https?:\/\//i.test(path)

    if (!viaApi) {
      setBlobUrl(path)
      return () => { cancelled = true }
    }

    api.get(path, { responseType: 'blob' })
      .then((r) => {
        if (cancelled) return
        const url = URL.createObjectURL(r.data)
        revoked = url
        setBlobUrl(url)
      })
      .catch(() => { if (!cancelled) setFailed(true) })
    return () => { cancelled = true; if (revoked) URL.revokeObjectURL(revoked) }
  }, [src])

  if (failed) {
    return (
      <div className={`${className} grid place-items-center text-xs text-slate-400 bg-slate-100`}>
        Image unavailable
      </div>
    )
  }
  if (!blobUrl) {
    return <div className={`${className} bg-slate-100 animate-pulse`} />
  }
  return (
    <img
      src={blobUrl}
      alt={alt || ''}
      className={className}
      style={{ objectFit: contain ? 'contain' : undefined, ...style }}
    />
  )
}
