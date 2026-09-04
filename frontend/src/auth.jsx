import { createContext, useContext, useEffect, useState } from 'react'
import api, { TOKEN_KEY, unwrap } from './api'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!localStorage.getItem(TOKEN_KEY)) { setLoading(false); return }
    api.get('/auth/me')
      .then((r) => setUser(unwrap(r).data))
      .catch(() => localStorage.removeItem(TOKEN_KEY))
      .finally(() => setLoading(false))
  }, [])

  const login = async (email, password) => {
    const res = unwrap(await api.post('/auth/login', { email, password }))
    if (res.data?.token) {
      localStorage.setItem(TOKEN_KEY, res.data.token)
      if (res.data.must_reset) {
        return { ok: true, mustReset: true }
      }
      const me = await api.get('/auth/me')
      setUser(unwrap(me).data)
      return { ok: true, mustReset: false }
    }
    return { ok: false, message: res.message || 'Login failed.' }
  }

  const refreshMe = async () => {
    const me = await api.get('/auth/me')
    setUser(unwrap(me).data)
    return unwrap(me).data
  }

  const logout = async () => {
    try { await api.post('/auth/logout') } catch {}
    localStorage.removeItem(TOKEN_KEY)
    setUser(null)
  }

  // Permission check against the RBAC checklist returned by /auth/me.
  const can = (...perms) => {
    const p = user?.permissions || []
    return p.includes('*') || perms.some((x) => p.includes(x))
  }

  return (
    <AuthCtx.Provider value={{ user, can, login, logout, refreshMe, loading }}>
      {children}
    </AuthCtx.Provider>
  )
}

export const useAuth = () => useContext(AuthCtx)