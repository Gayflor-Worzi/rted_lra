import { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react'
import NetInfo from '@react-native-community/netinfo'
import api, { configureAuth } from './api'
import {
  getDb,
  getAuthToken,
  setAuthToken,
  deleteAuthToken,
  kvGet,
  kvSet,
  kvDel,
  clearCachedTasks,
  clearReadCache,
} from './db'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [token, setToken] = useState(null)
  const [loading, setLoading] = useState(true)
  const [initError, setInitError] = useState('')
  const [online, setOnline] = useState(null)
  const [sessionValidated, setSessionValidated] = useState(false)
  const [sessionVersion, setSessionVersion] = useState(0)
  const sessionRef = useRef({ userId: null, token: null, version: 0 })
  const sessionVersionRef = useRef(0)
  const logoutPromiseRef = useRef(null)

  useEffect(() => {
    ;(async () => {
      try {
        await getDb()
        const t = await getAuthToken()
        const u = await kvGet('user')
        const restoredUser = u ? JSON.parse(u) : null
        if (t && restoredUser?.id !== null && restoredUser?.id !== undefined) {
          const version = sessionVersionRef.current + 1
          sessionVersionRef.current = version
          sessionRef.current = { userId: String(restoredUser.id), token: t, version }
          setSessionVersion(version)
          setToken(t)
          setUser(restoredUser)
        }
      } catch (error) {
        setInitError(error.message || 'Secure offline storage could not be initialized.')
      } finally {
        setLoading(false)
      }
    })()
  }, [])

  const logout = useCallback(async (requestedUserId = null) => {
    const currentSession = sessionRef.current
    if (requestedUserId != null && String(requestedUserId) !== String(currentSession.userId)) return
    if (logoutPromiseRef.current) return logoutPromiseRef.current
    const userId = currentSession.userId
    if (userId == null) return
    const version = currentSession.version + 1
    sessionVersionRef.current = version
    sessionRef.current = { userId: null, token: null, version }
    setSessionVersion(version)
    setToken(null)
    setUser(null)
    setSessionValidated(false)
    const cleanup = (async () => {
      await deleteAuthToken()
      await kvDel('user')
      await clearReadCache(userId)
      await clearCachedTasks()
    })()
    logoutPromiseRef.current = cleanup
    try {
      await cleanup
    } finally {
      if (logoutPromiseRef.current === cleanup) logoutPromiseRef.current = null
    }
  }, [])

  const isSessionCurrent = useCallback((userId, authToken, version) => {
    const current = sessionRef.current
    return current.userId === String(userId)
      && current.token === authToken
      && current.version === version
  }, [])

  const validateSession = useCallback(async () => {
    const session = sessionRef.current
    if (!session.token || session.userId == null) return false
    setSessionValidated(false)
    try {
      const response = await api.get('/auth/me', {
        headers: { Authorization: `Bearer ${session.token}` },
        authUserId: session.userId,
      })
      const currentUser = response.data?.data
      const stillCurrent = sessionRef.current.version === session.version
        && String(sessionRef.current.userId) === session.userId
        && sessionRef.current.token === session.token
      if (!stillCurrent) return false
      if (currentUser?.id == null || String(currentUser.id) !== session.userId) {
        await logout(session.userId)
        return false
      }
      await kvSet('user', JSON.stringify(currentUser))
      setUser(currentUser)
      setSessionValidated(true)
      return true
    } catch (error) {
      const stillCurrent = sessionRef.current.version === session.version
        && String(sessionRef.current.userId) === session.userId
      if (stillCurrent && (error.response?.status === 401 || error.response?.status === 403)) {
        await logout(session.userId)
      }
      return false
    }
  }, [logout])

  useEffect(() => {
    configureAuth(
      () => sessionRef.current.token,
      (requestConfig) => {
        const requestUserId = requestConfig?.authUserId
        if (requestUserId != null && String(requestUserId) !== String(sessionRef.current.userId)) return
        return logout(requestUserId)
      },
      () => sessionRef.current.userId,
    )
  }, [logout])

  useEffect(() => {
    let previousConnection = null
    const unsubscribe = NetInfo.addEventListener((state) => {
      const connected = state.isConnected === true && state.isInternetReachable !== false
      setOnline(connected)
      if (connected && token && previousConnection !== true) {
        validateSession()
      }
      previousConnection = connected
    })
    return unsubscribe
  }, [token, validateSession])

  const login = async (email, password) => {
    if (sessionRef.current.userId != null) await logout(sessionRef.current.userId)
    if (logoutPromiseRef.current) await logoutPromiseRef.current
    const r = await api.post('/auth/login', { email, password })
    const { user: u, token: t } = r.data.data
    if (!t || !u || u.id == null) throw Object.assign(new Error('Login response missing token or stable account ID.'), { response: r })
    await setAuthToken(t)
    await kvSet('user', JSON.stringify(u))
    const version = sessionVersionRef.current + 1
    sessionVersionRef.current = version
    sessionRef.current = { userId: String(u.id), token: t, version }
    setSessionVersion(version)
    setToken(t)
    setUser(u)
    setSessionValidated(true)
  }

  const updateUser = async (patch) => {
    setUser((prev) => {
      const next = { ...(prev || {}), ...patch }
      kvSet('user', JSON.stringify(next))
      return next
    })
  }

  return (
    <AuthCtx.Provider value={{
      user,
      token,
      loading,
      initError,
      online,
      sessionValidated,
      sessionVersion,
      canUseOfflineCache: online === false || sessionValidated,
      isSessionCurrent,
      validateSession,
      login,
      logout,
      updateUser,
    }}>
      {children}
    </AuthCtx.Provider>
  )
}

export const useAuth = () => useContext(AuthCtx)
