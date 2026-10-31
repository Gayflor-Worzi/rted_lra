import { deleteReadCache, readReadCache, writeReadCache } from './db'
import { Platform } from 'react-native'

function canUseFallback(error) {
  const status = error?.response?.status
  return !status || [408, 425, 429].includes(status) || status >= 500
}

export async function fetchOfflineRead({
  module,
  cacheKey,
  user,
  request,
  select = (response) => response?.data?.data,
  cacheSelect,
  cacheCondition = () => true,
  allowCache,
}) {
  if (!user?.id) throw new Error('Offline reads require an authenticated user.')

  const cacheAvailable = Platform.OS !== 'web'
  let response
  try {
    response = await request()
  } catch (requestError) {
    if ([401, 403].includes(requestError?.response?.status)) {
      await deleteReadCache(module, cacheKey, user.id)
      throw requestError
    }
    if (!cacheAvailable || !allowCache || !canUseFallback(requestError)) throw requestError

    const cached = await readReadCache(module, cacheKey, user.id)
    if (cached?.status === 'cached') return cached
    if (cached?.status === 'expired') return cached
    throw requestError
  }

  const data = select(response)
  if (!cacheAvailable) {
    return { status: 'live-cache-unavailable', data, syncedAt: new Date().toISOString() }
  }
  if (!cacheCondition(data)) {
    await deleteReadCache(module, cacheKey, user.id)
    return { status: 'live-not-cached', data, syncedAt: new Date().toISOString() }
  }
  let cacheError = null
  try {
    await writeReadCache(module, cacheKey, user.id, cacheSelect ? cacheSelect(data) : data)
  } catch (error) {
    cacheError = error
  }
  return {
    status: cacheError ? 'live-cache-failed' : 'live',
    data,
    syncedAt: new Date().toISOString(),
    cacheError,
  }
}
