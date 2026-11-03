import { useCallback, useState } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, ActivityIndicator } from 'react-native'
import { useFocusEffect, useNavigation } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { markCachedNotificationsRead } from '../db'
import { useSync } from '../sync'
import { hasAny, serverMessage } from '../rbac'
import { theme } from '../theme'
import { Screen, BrandHeader, Card, Badge, Btn, OfflineReadStatus } from '../components'

const timeAgo = (iso) => {
  if (!iso) return ''
  const diff = Math.max(0, Date.now() - new Date(iso).getTime())
  const m = Math.floor(diff / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24)
  if (d < 30) return `${d}d ago`
  return new Date(iso).toLocaleDateString()
}

const TYPE_ICON = { broadcast: '📢', task: '📋', payment: '🧾', audit: '🛡️', organization: '🏛️', system: '⚙️' }

export default function NoticesScreen() {
  const insets = useSafeAreaInsets()
  const navigation = useNavigation()
  const { user, canUseOfflineCache } = useAuth()
  const { queueAndFlush } = useSync()
  const [items, setItems] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [readBusy, setReadBusy] = useState(false)
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)

  const load = useCallback(async (showSpinner = false) => {
    if (showSpinner) setRefreshing(true)
    try {
      const result = await fetchOfflineRead({
        module: 'notices',
        cacheKey: `${user?.role}:list:50`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/notifications', { params: { per_page: 50 } }),
        select: (response) => response.data?.data?.data || response.data?.data || [],
        cacheSelect: (notices) => Array.isArray(notices)
          ? notices.filter((item) => !String(item?.type || '').toLowerCase().includes('payment'))
          : [],
      })
      if (result.status === 'expired') {
        setItems([])
        setError('Offline notices have expired. Connect to refresh.')
      } else {
        setItems(result.data)
        setError('')
      }
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
    } catch (e) {
      setError(serverMessage(e, 'Could not load notices.'))
      setItems([])
      setReadStatus(null)
    } finally { setRefreshing(false) }
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load]))

  const markRead = async (n) => {
    if (n.read_at || readStatus === 'expired') return
    const readAt = new Date().toISOString()
    try {
      await queueAndFlush('notification_read', { notification_id: n.id })
      await markCachedNotificationsRead(user.id, n.id, readAt)
      setItems((rows) => rows.map((x) => (x.id === n.id ? { ...x, read_at: readAt } : x)))
    } catch (cause) {
      setError(cause?.message || 'The notice read state could not be saved locally.')
    }
  }

  const markAll = async () => {
    if (readStatus === 'expired') return
    setReadBusy(true)
    const readAt = new Date().toISOString()
    try {
      await queueAndFlush('notification_read_all', {})
      await markCachedNotificationsRead(user.id, null, readAt)
      setItems((rows) => rows.map((x) => (x.read_at ? x : { ...x, read_at: readAt })))
    } catch (cause) {
      setError(cause?.message || 'Notice read states could not be saved locally.')
    }
    finally { setReadBusy(false) }
  }

  const unread = items?.filter((n) => !n.read_at).length || 0
  const canOpenOrganization = hasAny(user, [
    'enforcement.org.manage',
    'enforcement.org.recommend',
    'enforcement.org.review',
  ])

  const open = (item) => {
    markRead(item)
    if (item.type === 'organization' && canOpenOrganization) {
      navigation.navigate('EnforcementOrganization')
    }
  }

  const row = ({ item }) => (
    <TouchableOpacity onPress={() => open(item)} activeOpacity={0.7}>
      <Card style={[s.card, !item.read_at && s.unreadCard]}>
        <View style={s.rowTop}>
          <Text style={s.icon}>{TYPE_ICON[item.type] || '🔔'}</Text>
          <View style={{ flex: 1 }}>
            <Text style={s.title} numberOfLines={2}>{item.title}</Text>
            {item.message ? <Text style={s.msg} numberOfLines={3}>{item.message}</Text> : null}
          </View>
          {!item.read_at && <View style={s.dot} />}
        </View>
        <View style={s.meta}>
          {item.type ? <Badge label={item.type} /> : null}
          <Text style={s.time}>{timeAgo(item.created_at)}</Text>
        </View>
      </Card>
    </TouchableOpacity>
  )

  return (
    <Screen scroll={false}>
      <BrandHeader
        title="Notices"
        subtitle={unread > 0 ? `${unread} unread` : 'All caught up'}
        right={
          unread > 0 && readStatus !== 'expired' ? (
            <TouchableOpacity onPress={markAll} disabled={readBusy} hitSlop={8}>
              <Text style={{ color: theme.colors.primary, fontWeight: '800', fontSize: 12.5 }}>{readBusy ? '…' : 'Mark all read'}</Text>
            </TouchableOpacity>
          ) : null
        }
      />
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />

      {items === null ? (
        <View style={{ paddingTop: 60, alignItems: 'center' }}>
          <ActivityIndicator size="large" color={theme.colors.primary} />
          <Text style={{ color: theme.colors.textMuted, marginTop: 12, fontSize: 13 }}>Loading notices…</Text>
        </View>
      ) : error ? (
        <Card style={{ marginTop: 20 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load notices</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{error}</Text>
          <Btn label="⟳ Retry" onPress={() => load(true)} />
        </Card>
      ) : (
        <FlatList
          data={items}
          keyExtractor={(i) => String(i.id)}
          renderItem={row}
          contentContainerStyle={{ paddingVertical: 8, paddingBottom: 24 + insets.bottom }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={theme.colors.primary} />}
          ListEmptyComponent={
            <View style={{ paddingTop: 80, alignItems: 'center' }}>
              <Text style={{ fontSize: 40 }}>🔔</Text>
              <Text style={{ color: theme.colors.navy, fontWeight: '800', marginTop: 12 }}>No notices</Text>
              <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginTop: 4, textAlign: 'center', paddingHorizontal: 32 }}>
                Supervisors use notifications to alert you to assignments, escalations and payment verification.
              </Text>
            </View>
          }
        />
      )}
    </Screen>
  )
}

const s = StyleSheet.create({
  card: { marginHorizontal: 16, marginBottom: 8 },
  unreadCard: { borderLeftWidth: 3, borderLeftColor: theme.colors.primary },
  rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  icon: { fontSize: 18, marginTop: 1 },
  title: { fontSize: 14, fontWeight: '700', color: theme.colors.navy },
  msg: { fontSize: 12.5, color: theme.colors.textMuted, marginTop: 3, lineHeight: 17 },
  dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: theme.colors.primary, marginTop: 5 },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 8 },
  time: { fontSize: 11, color: theme.colors.textLight, fontWeight: '600' },
})