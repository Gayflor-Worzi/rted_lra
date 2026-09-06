import axios from 'axios'

export const TOKEN_KEY = 'retd_token'

const api = axios.create({ baseURL: import.meta.env.VITE_API_BASE || '/api/v1', headers: { Accept: 'application/json' }, timeout: 60000 })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Render's free tier spins the instance down after ~15m idle; the first request
// after a cold start can 502/timeout. Retrying idempotent reads (GET) once the
// origin has had a moment to boot lets pages self-heal instead of sticking on ⚠️.
const RETRYABLE = [408, 425, 429, 500, 502, 503, 504]

const canRetry = (config, err) => {
  if (!['get', 'head', 'options', undefined].includes((config?.method || 'get').toLowerCase())) return false
  if ((config.retryCount ?? 0) >= (config.retries ?? 2)) return false
  const status = err?.response?.status
  if (status) return RETRYABLE.includes(status)
  return !err?.code || err?.code === 'ECONNABORTED' // network errors + timeouts retryable
}

api.interceptors.response.use(
  (r) => r,
  async (err) => {
    // Keep the 401 bounce off the login + force-reset screens.
    if (err.response?.status === 401 && !['/login', '/force-reset'].includes(location.pathname)) {
      localStorage.removeItem(TOKEN_KEY)
      location.href = '/login'
      return Promise.reject(err)
    }
    const config = err.config
    if (!config || !canRetry(config, err)) return Promise.reject(err)
    config.retryCount = (config.retryCount ?? 0) + 1
    await new Promise((res) => setTimeout(res, 800 * 2 ** config.retryCount))
    return api.request(config)
  },
)

export const unwrap = (r) => r.data

/** Pull the human-readable message out of an axios error. */
export const errMsg = (err, fallback = 'Something went wrong.') => {
  if (err?.response?.data?.message) return err.response.data.message
  if (err?.response?.data?.errors) {
    const first = Object.values(err.response.data.errors)[0]
    return Array.isArray(first) ? first[0] : first
  }
  if (err?.message) return err.message
  return fallback
}

export default api