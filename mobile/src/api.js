import axios from 'axios'
import { API_BASE } from './config'

let tokenGetter = () => null
let onUnauthorized = () => {}

const api = axios.create({ baseURL: `${API_BASE}/api/v1`, timeout: 20000 })

api.interceptors.request.use((cfg) => {
  const t = tokenGetter()
  if (t) cfg.headers.Authorization = `Bearer ${t}`
  cfg.headers.Accept = 'application/json'
  return cfg
})

api.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) onUnauthorized()
    return Promise.reject(err)
  }
)

export const configureAuth = (getter, logoutFn) => {
  tokenGetter = getter
  onUnauthorized = logoutFn
}

export default api
