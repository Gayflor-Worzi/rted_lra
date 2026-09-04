import axios from 'axios'

export const TOKEN_KEY = 'retd_token'

const api = axios.create({ baseURL: import.meta.env.VITE_API_BASE || '/api/v1', headers: { Accept: 'application/json' } })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (r) => r,
  (err) => {
    // Keep the 401 bounce off the login + force-reset screens.
    if (err.response?.status === 401 && !['/login', '/force-reset'].includes(location.pathname)) {
      localStorage.removeItem(TOKEN_KEY)
      location.href = '/login'
    }
    return Promise.reject(err)
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