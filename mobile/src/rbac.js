export function can(user, permission) {
  if (!user?.permissions) return false
  const perms = user.permissions
  if (perms.includes('*')) return true
  return perms.includes(permission)
}

export function hasAny(user, permissions) {
  return permissions.some((p) => can(user, p))
}

export function hasAll(user, permissions) {
  return permissions.every((p) => can(user, p))
}

export function serverMessage(e, fallback) {
  return e?.response?.data?.message || fallback
}

// A server response (4xx/5xx) means the backend REJECTED the request.
// Only a missing response (network) should fall back to the offline queue.
export function isOnlineRejection(e) {
  return !!e?.response
}