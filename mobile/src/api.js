import axios from 'axios'
import { API_BASE } from './config'

let tokenGetter = () => null
let onUnauthorized = () => {}

const api = axios.create({ baseURL: `${API_BASE}/api/v1`, timeout: 60000 })

api.interceptors.request.use((cfg) => {
  const t = tokenGetter()
  if (t) cfg.headers.Authorization = `Bearer ${t}`
  cfg.headers.Accept = 'application/json'
  return cfg
})

// Render's free tier spins down after ~15m idle; the first request after a cold
// start can 502/timeout, so retry idempotent reads with backoff and let the app
// self-heal. Mutations are excluded so outbox items never double-submit.
const RETRYABLE = [408, 425, 429, 500, 502, 503, 504]

const canRetry = (config, err) => {
  if (!['get', 'head', 'options', undefined].includes((config?.method || 'get').toLowerCase())) return false
  if ((config.retryCount ?? 0) >= (config.retries ?? 2)) return false
  const status = err?.response?.status
  if (status) return RETRYABLE.includes(status)
  return !err?.code || err?.code === 'ECONNABORTED'
}

api.interceptors.response.use(
  (r) => r,
  async (err) => {
    if (err.response?.status === 401) onUnauthorized()
    const config = err.config
    if (!config || !canRetry(config, err)) return Promise.reject(err)
    config.retryCount = (config.retryCount ?? 0) + 1
    await new Promise((res) => setTimeout(res, 800 * 2 ** config.retryCount))
    return api.request(config)
  }
)

export const configureAuth = (getter, logoutFn) => {
  tokenGetter = getter
  onUnauthorized = logoutFn
}

export default api
