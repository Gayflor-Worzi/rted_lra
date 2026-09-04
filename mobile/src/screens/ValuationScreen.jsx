import { useState, useCallback } from 'react'
import { View, Text, TextInput, StyleSheet, ScrollView, Alert, TouchableOpacity, useWindowDimensions, RefreshControl } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { can, serverMessage } from '../rbac'
import { theme } from '../theme'
import { BrandHeader, Card, Badge, Empty } from '../components'

const STATUSES = ['Draft', 'Submitted', 'Manager Review', 'AC Approval', 'Approved', 'Rejected', 'Returned']

const fd = (v) => (v ? String(v).slice(0, 10) : '—')
const fmMoney = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

export default function ValuationScreen({ navigation }) {
  const { user } = useAuth()
  const { width } = useWindowDimensions()
  const canCreate = can(user, 'valuation.create')
  const canView = can(user, 'valuation.view_history') || can(user, 'valuation.review')

  const [list, setList] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('All')

  const loadList = useCallback(async (show = true) => {
    if (show) setRefreshing(true)
    try {
      const params = { per_page: 50 }
      if (status !== 'All') params.status = status
      if (query.trim()) params.q = query.trim()
      const r = await api.get('/valuations', { params })
      setList(r.data.data?.data || r.data.data || [])
      setErr('')
    } catch (e) {
      setErr(serverMessage(e, 'Could not load valuations.'))
      setList([])
    }
    if (show) setRefreshing(false)
  }, [status, query])

  useFocusEffect(useCallback(() => { loadList(false) }, [loadList]))

  const refresh = async () => { setRefreshing(true); await loadList(false); setRefreshing(false) }

  if (!canCreate && !canView) {
    return (
      <View style={s.container}>
        <BrandHeader title="Valuations" />
        <Empty icon="🚫" title="No access" sub="Your role does not include valuation permissions." />
      </View>
    )
  }

  return (
    <View style={s.container}>
      <BrandHeader title="Valuations" subtitle={list == null ? 'Loading…' : `${list.length} assigned record(s)`} />

      <ScrollView
        contentContainerStyle={{ paddingBottom: 140, width: '100%', maxWidth: 800, alignSelf: 'center' }}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}
      >
        {canCreate && (
          <View style={s.ctaWrap}>
            <TouchableOpacity onPress={() => navigation.navigate('NewValuation')} activeOpacity={0.85} style={s.cta}>
              <Text style={s.ctaIcon}>＋</Text>
              <View style={{ flex: 1 }}>
                <Text style={s.ctaTitle}>Add New Assessment</Text>
                <Text style={s.ctaSub}>Record a new property valuation</Text>
              </View>
              <Text style={s.ctaArrow}>›</Text>
            </TouchableOpacity>
          </View>
        )}

        <View style={s.filterWrap}>
          <TextInput
            style={s.search}
            placeholder="Search by owner, address or reference…"
            placeholderTextColor={theme.colors.textLight}
            value={query}
            onChangeText={(t) => setQuery(t)}
            returnKeyType="search"
            onSubmitEditing={() => loadList(false)}
          />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}>
            {['All', ...STATUSES].map((st) => {
              const active = status === st
              return (
                <TouchableOpacity key={st} onPress={() => setStatus(st)} activeOpacity={0.8}
                  style={[s.statusChip, active && { backgroundColor: theme.colors.navy, borderColor: theme.colors.navy }]}>
                  <Text style={[s.statusChipText, active && { color: '#fff' }]}>{st}</Text>
                </TouchableOpacity>
              )
            })}
          </ScrollView>
        </View>

        {err && (
          <Card style={{ marginHorizontal: 16, marginTop: 12 }}>
            <Text style={{ color: theme.colors.danger, fontWeight: '600' }}>{err}</Text>
          </Card>
        )}

        <Text style={s.sectionLabel}>PROPERTIES & ASSESSMENTS</Text>
        {list == null ? (
          <Empty icon="⏳" title="Loading…" sub="Fetching assigned valuations." />
        ) : list.length === 0 ? (
          <Empty icon="🏷️" title="No valuations" sub={status !== 'All' || query ? 'No records match this filter.' : 'Valuations assigned to you appear here.'} />
        ) : (
          <View style={s.grid}>
            {list.map((d) => (
              <Card key={d.id} style={{ flexBasis: width > 700 ? '47%' : '100%' }} onPress={() => navigation.navigate('ValuationDetail', { valuationId: d.id })}>
                <View style={s.cardTop}>
                  <Text style={s.ref} numberOfLines={1}>{d.valuation_reference}</Text>
                  <Badge status={d.status} label={d.status} />
                </View>
                <Text style={s.owner} numberOfLines={1}>{d.owner_name || '—'}</Text>
                <Text style={s.addr} numberOfLines={2}>{d.property_address || '—'}</Text>
                <View style={s.metaRow}>
                  <Text style={s.meta}>{fd(d.assessment_date)}</Text>
                  <Text style={s.money}>{fmMoney(d.total_tax_payable ?? d.assessed_value ?? d.reassessed_value ?? d.annual_tax)}</Text>
                </View>
                <View style={s.foot}>
                  <Text style={s.meta} numberOfLines={1}>{d.valuation_officer || 'Unassigned'}</Text>
                  <Text style={s.open}>Open ›</Text>
                </View>
              </Card>
            ))}
          </View>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  ctaWrap: { marginHorizontal: 16, marginTop: 14, marginBottom: 4 },
  cta: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: theme.colors.primary, borderRadius: 14, paddingHorizontal: 16, paddingVertical: 14, minHeight: 60 },
  ctaIcon: { fontSize: 26, color: '#fff', fontWeight: '800' },
  ctaTitle: { color: '#fff', fontSize: 15, fontWeight: '800' },
  ctaSub: { color: 'rgba(255,255,255,0.85)', fontSize: 12, marginTop: 1 },
  ctaArrow: { fontSize: 22, color: '#fff', fontWeight: '800' },
  filterWrap: { marginTop: 12 },
  search: { backgroundColor: '#fff', borderRadius: 12, marginHorizontal: 16, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, borderWidth: 1.5, borderColor: theme.colors.border, color: theme.colors.text },
  statusChip: { borderRadius: 16, paddingHorizontal: 13, paddingVertical: 7, minHeight: 36, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: theme.colors.border, backgroundColor: '#fff' },
  statusChipText: { fontSize: 12, fontWeight: '700', color: theme.colors.textMuted },
  sectionLabel: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, letterSpacing: 0.8, marginLeft: 16, marginTop: 22, marginBottom: 10 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: 16 },
  cardTop: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  ref: { flex: 1, fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  owner: { fontSize: 14, fontWeight: '700', color: theme.colors.text, marginTop: 8 },
  addr: { fontSize: 12.5, color: theme.colors.textMuted, marginTop: 3, lineHeight: 17 },
  metaRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 },
  meta: { fontSize: 11.5, color: theme.colors.textLight, fontWeight: '600' },
  money: { fontSize: 13, fontWeight: '800', color: theme.colors.primary },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10, borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 8 },
  open: { fontSize: 12.5, fontWeight: '700', color: theme.colors.primary },
})
