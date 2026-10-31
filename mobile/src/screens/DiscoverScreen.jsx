import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { can } from '../rbac'
import { theme } from '../theme'
import { Screen, BrandHeader, Card, Empty, Badge, OfflineReadStatus } from '../components'

export default function DiscoverScreen({ navigation }) {
  const { user, canUseOfflineCache } = useAuth()
  const canCreate = can(user, 'discovery.create')
  const canView = can(user, 'discovery.view') || can(user, 'discovery.review') || canCreate
  const [recent, setRecent] = useState(null)
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [loadError, setLoadError] = useState('')

  const loadRecent = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'discoveries',
        cacheKey: `${user?.role}:recent`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/discoveries', { params: { per_page: 10, officer_id: user?.id } }),
        select: (response) => response.data?.data?.data || response.data?.data || [],
        cacheSelect: (records) => Array.isArray(records)
          ? records.filter((record) => String(record?.discovered_by) === String(user.id))
          : [],
      })
      setRecent(result.status === 'expired' ? [] : result.data)
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
      setLoadError(result.status === 'expired' ? 'Offline discovery data has expired. Connect to refresh.' : '')
    } catch {
      setRecent([])
      setReadStatus(null)
      setLoadError('Could not load discoveries. Connect to the network and retry.')
    }
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { loadRecent() }, [loadRecent]))

  return (
    <Screen scroll>
      <BrandHeader
        title="Property Discoveries"
        subtitle={recent ? `${recent.length} recently recorded by you` : 'Field discoveries'}
        right={canCreate ? (
          <TouchableOpacity onPress={() => navigation.navigate('NewDiscovery')} style={s.newBtn}>
            <Text style={s.newBtnText}>+ New</Text>
          </TouchableOpacity>
        ) : null}
      />

      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
      {loadError ? <Card style={{ marginTop: 12 }}><Text style={{ color: theme.colors.danger }}>{loadError}</Text></Card> : null}

      {loadError ? null : canView && recent !== null && recent.length > 0 ? (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.cardHeading}>Recent Discoveries</Text>
          {recent.map((d) => (
            <TouchableOpacity key={d.id} style={s.recentRow} onPress={() => navigation.navigate('DiscoveryDetail', { discoveryId: d.id })}>
              <View style={{ flex: 1 }}>
                <Text style={s.recentRef}>{d.discovery_reference}</Text>
                <Text style={s.recentAddr}>{d.property_address || '—'}</Text>
              </View>
              <Badge status={d.status} label={d.status} />
            </TouchableOpacity>
          ))}
        </Card>
      ) : (
        <Empty
          icon="🏠"
          title="No discoveries yet"
          message={canCreate ? 'Tap + New to record your first property discovery.' : 'You have no recorded discoveries yet.'}
        />
      )}
    </Screen>
  )
}

const s = StyleSheet.create({
  cardHeading: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 },
  newBtn: { backgroundColor: theme.colors.primary, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 9, minHeight: 44, justifyContent: 'center' },
  newBtnText: { color: '#fff', fontWeight: '800', fontSize: 13 },
  recentRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: theme.colors.border, gap: 8 },
  recentRef: { fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  recentAddr: { fontSize: 12, color: theme.colors.textMuted, marginTop: 2 },
})
