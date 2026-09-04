import { createContext, useContext, useState, useEffect } from 'react'
import api, { configureAuth } from './api'
import { getDb, kvGet, kvSet, kvDel } from './db'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [token, setToken] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    ;(async () => {
      await getDb()
      const t = await kvGet('token')
      const u = await kvGet('user')
      if (t && u) {
        setToken(t)
        try { setUser(JSON.parse(u)) } catch { setUser(null) }
        configureAuth(() => t, logout)
      }
      setLoading(false)
    })()
  }, [])

  const login = async (email, password) => {
    const r = await api.post('/auth/login', { email, password })
    const { user: u, token: t } = r.data.data
    if (!t || !u) throw Object.assign(new Error('Login response missing token or user.'), { response: r })
    await kvSet('token', t)
    await kvSet('user', JSON.stringify(u))
    setToken(t)
    setUser(u)
    configureAuth(() => t, logout)
  }

  const updateUser = async (patch) => {
    setUser((prev) => {
      const next = { ...(prev || {}), ...patch }
      kvSet('user', JSON.stringify(next))
      return next
    })
  }

  const logout = async () => {
    setToken(null)
    setUser(null)
    await kvDel('token')
    await kvDel('user')
  }

  return (
    <AuthCtx.Provider value={{ user, token, loading, login, logout, updateUser }}>
      {children}
    </AuthCtx.Provider>
  )
}

export const useAuth = () => useContext(AuthCtx)
