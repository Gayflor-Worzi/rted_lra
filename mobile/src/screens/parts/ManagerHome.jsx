import { useCallback, useEffect, useState } from 'react'
import { AppState, View, Text, TouchableOpacity, StyleSheet } from 'react-native'
import api from '../../api'
import { theme } from '../../theme'
import { Card } from '../../components'

// Role → manager dashboard type + endpoint. Mirrors the backend gate exactly.
const TYPE_ROLE = {
  'Account Manager': { type: 'account', endpoint: '/dashboard/manager/account', label: 'Account & Record Manager' },
  'Valuation Manager': { type: 'valuation', endpoint: '/dashboard/manager/valuation', label: 'Valuation Manager' },
  'Enforcement Manager': { type: 'enforcement', endpoint: '/dashboard/manager/enforcement', label: 'Enforcement Manager' },
  'Enforcement Supervisor': { type: 'enforcement', endpoint: '/dashboard/manager/enforcement', label: 'Enforcement Manager' },
  'Assistant Commissioner': { type: 'ac', endpoint: '/dashboard/ac', label: 'Assistant Commissioner Dashboard' },
}

const fmtNum = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v).toLocaleString()}`
}

const fmtMoney = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

export default function ManagerHome({ user, navigation, refreshKey = 0 }) {
  const cfg = TYPE_ROLE[user?.role]
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [loaded, setLoaded] = useState(false)
  const [expandId, setExpandId] = useState(null)

  const load = useCallback(async () => {
    if (!cfg) return
    try {
      const r = await api.get(cfg.endpoint)
      setData(r.data?.data || r.data || {})
      setErr('')
    } catch (e) {
      setErr('Could not load your manager dashboard.')
    }
    setLoaded(true)
  }, [cfg])

  useEffect(() => { load() }, [load, refreshKey])
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') load()
    })
    return () => subscription.remove()
  }, [load])

  if (!cfg) return null

  const kpis = (data?.kpis || []).slice(0, 8)
  const monthly = data?.monthly || []
  const alerts = data?.alerts || []
  const financial = data?.financial || []
  const priority = data?.priority?.rows || []
  const workloadRows = data?.workload?.rows || []
  const workloadCols = data?.workload?.columns || []
  const reporting = data?.meta?.reporting || {}

  return (
    <View>
<Card style={styles.block}>
        <Text style={styles.cardHeader}>Key Figures</Text>
        {!loaded ? (
          <Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>Loading dashboard…</Text>
        ) : err ? (
          <View>
            <Text style={{ color: theme.colors.danger, fontSize: 13 }}>{err}</Text>
            <TouchableOpacity onPress={load} style={{ marginTop: 8, minHeight: 40, justifyContent: 'center' }}>
              <Text style={{ color: theme.colors.primary, fontWeight: '700', fontSize: 13 }}>⟳ Retry</Text>
            </TouchableOpacity>
          </View>
        ) : (
          <View style={styles.kpiGrid}>
            {kpis.map((k) => (
              <View key={k.key} style={styles.kpiTile}>
                <Text style={[styles.kpiValue, { color: k.tone === 'red' ? theme.colors.danger : k.tone === 'green' ? theme.colors.success : k.tone === 'amber' ? theme.colors.warning : theme.colors.navy }]}>
                  {k.money ? fmtMoney(k.value) : fmtNum(k.value)}
                </Text>
                <Text style={styles.kpiLabel} numberOfLines={2}>{k.label}</Text>
                <Text style={styles.kpiKind}>{k.kind === 'period' ? 'This Month' : 'Current'}</Text>
              </View>
            ))}
          </View>
        )}
      </Card>

      {monthly.length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>{`This Month · ${reporting?.period?.period || reporting?.period_label || monthly[0]?.period || 'Current Month'}`}</Text>
          <Text style={styles.rowDetail}>Period activity · event-based</Text>
          {monthly.map((m, i) => (
            <View key={m.key || i} style={[styles.row, i > 0 && styles.rowBorder]}>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowLabel} numberOfLines={1}>{m.label}</Text>
                <Text style={styles.rowDetail} numberOfLines={1}>{m.event} · {m.event_field}</Text>
              </View>
              <Text style={styles.rowValue}>{fmtNum(m.value)}</Text>
            </View>
          ))}
        </Card>
      )}

      {financial.length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>Financial Summary</Text>
          <View style={styles.kpiGrid}>
            {financial.map((f, i) => (
              <View key={i} style={styles.kpiTile}>
                <Text style={[styles.kpiValue, { color: theme.colors.navy }]}>{fmtMoney(f.value)}</Text>
                <Text style={styles.kpiLabel} numberOfLines={2}>{f.label}</Text>
              </View>
            ))}
          </View>
        </Card>
      )}

      {alerts.length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>Alerts</Text>
          {alerts.map((a, i) => (
            <View key={i} style={[styles.row, i > 0 && styles.rowBorder]}>
              <Text style={styles.rowLabel} numberOfLines={1}>{a.label}</Text>
              <Text style={[styles.rowValue, { color: a.tone === 'red' ? theme.colors.danger : a.tone === 'amber' ? theme.colors.warning : theme.colors.navy }]}>
                {fmtNum(a.count)}
              </Text>
            </View>
          ))}
        </Card>
      )}

      {workloadRows.length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>{data?.workload?.title || 'Officer Workload'}</Text>
          {workloadRows.map((r, i) => {
            const open = expandId === r.officer_id
            const keys = Object.keys(r.cols || {})
            const labels = (data?.workload?.columns || []).slice(1)
            const vals = labels.map((_, j) => Number(r.cols?.[keys[j]] ?? 0))
            const total = vals.reduce((s, v) => s + v, 0)
            const max = Math.max(total, 1)
            const exclusiveWorkload = data?.workload?.mode === 'exclusive'
            const asg = exclusiveWorkload
              ? Number(r.cols?.active || 0) + Number(r.cols?.completed || 0)
              : r.cols?.assigned ?? 0
            const done = r.cols?.completed ?? 0
            const rr = asg > 0 ? Math.round((done / asg) * 100) : null
            const segColor = (label) =>
              /completed/i.test(label) ? '#10B981'
                : /in.?progress/i.test(label) ? '#0EA5E9'
                  : /review|verification/i.test(label) ? '#F59E0B'
                    : /delivery/i.test(label) ? '#8B5CF6'
                      : /follow.?up/i.test(label) ? '#EC4899'
                        : /notices/i.test(label) ? '#14B8A6'
                          : /returned/i.test(label) ? '#EF4444'
                            : /overdue/i.test(label) ? '#002060'
                              : /assigned/i.test(label) ? '#94A3B8' : '#64748B'
            return (
              <TouchableOpacity key={r.officer_id ?? i} onPress={() => setExpandId(open ? null : r.officer_id)}
                style={[styles.row, i > 0 && styles.rowBorder, styles.wRow]}>
                <View style={{ flex: 1 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                    <Text style={styles.rowLabel} numberOfLines={1}>{r.officer}</Text>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                      {rr !== null && (
                        <Text style={{ fontSize: 13, fontWeight: '800', color: rr >= 60 ? theme.colors.success : rr >= 30 ? theme.colors.warning : theme.colors.danger }}>
                          {rr}%
                        </Text>
                      )}
                      <Text style={styles.rowDetail}>{total} records</Text>
                      <Text style={{ fontSize: 11, color: theme.colors.textLight }}>{open ? '▲' : '▼'}</Text>
                    </View>
                  </View>
                  <View style={styles.stackTrack}>
                    {vals.map((v, j) => v > 0 && (
                      <View key={`${r.officer_id}-${j}`} style={{ width: `${Math.max((v / max) * 100, 1.5)}%`, height: '100%', backgroundColor: segColor(labels[j] || '') }} />
                    ))}
                  </View>
                </View>
                {open && (
                  <View style={{ width: '100%', marginTop: 10, paddingTop: 10, borderTopWidth: 1, borderTopColor: theme.colors.border }}>
                    {labels.map((l, j) => vals[j] > 0 && (
                      <View key={`${r.officer_id}-d-${j}`} style={styles.row}>
                        <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                          <View style={{ width: 10, height: 10, borderRadius: 2, backgroundColor: segColor(l) }} />
                          <Text style={styles.rowDetail} numberOfLines={1}>{l}</Text>
                        </View>
                        <Text style={styles.rowValue}>{fmtNum(vals[j])}</Text>
                      </View>
                    ))}
                    {exclusiveWorkload && (data?.workload?.operational_metrics || []).map((metric) => (
                      <View key={`${r.officer_id}-op-${metric.key}`} style={styles.row}>
                        <Text style={[styles.rowDetail, { flex: 1 }]}>{metric.label} · subset</Text>
                        <Text style={styles.rowValue}>{fmtNum(r.operational?.[metric.key] || 0)}</Text>
                      </View>
                    ))}
                  </View>
                )}
              </TouchableOpacity>
            )
          })}
        </Card>
      )}

      {priority.length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>{data?.priority?.title || 'Manager Attention'}</Text>
          {priority.map((p, i) => (
            <View key={i} style={[styles.row, i > 0 && styles.rowBorder]}>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowLabel} numberOfLines={1}>{p.reference} · {p.label}</Text>
                <Text style={styles.rowDetail} numberOfLines={1}>
                  {p.staff && p.staff !== '—' ? `${p.staff} · ` : ''}{p.status}{p.due && p.due !== '—' ? ` · due ${p.due}` : ''}
                </Text>
              </View>
              <Text style={styles.rowAction}>{p.action}</Text>
            </View>
          ))}
        </Card>
      )}

      {(data?.activity || []).length > 0 && (
        <Card style={styles.block}>
          <Text style={styles.cardHeader}>Recent Activity</Text>
          {data.activity.slice(0, 8).map((a, i) => (
            <View key={i} style={[styles.row, i > 0 && styles.rowBorder]}>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowLabel} numberOfLines={1}>{a.event}</Text>
                <Text style={styles.rowDetail} numberOfLines={1}>{a.reference}</Text>
              </View>
              <Text style={styles.rowDetail}>{a.time ? String(a.time).slice(0, 10) : ''}</Text>
            </View>
          ))}
        </Card>
      )}

      {reporting && (
        <View style={styles.reportBanner}>
          <Text style={styles.reportText}>
            {reporting.period_label ? `${reporting.period_label} · ` : ''}
            Data as of {reporting.as_of || '—'}
            {data?.meta?.timezone ? ` (${data.meta.timezone})` : ''}
          </Text>
        </View>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  block: { marginTop: 12 },
  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 },
  kpiGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  kpiTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 10, paddingHorizontal: 8 },
  kpiValue: { fontSize: 17, fontWeight: '800' },
  kpiLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 3, fontWeight: '600' },
  kpiKind: { fontSize: 8, color: theme.colors.textMuted, marginTop: 2, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.3 },
  reportBanner: { marginTop: 14, borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, backgroundColor: '#F8FAFC', paddingVertical: 8, paddingHorizontal: 12 },
  reportText: { fontSize: 11, color: theme.colors.textMuted, textAlign: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, paddingVertical: 8 },
  rowBorder: { borderTopWidth: 1, borderTopColor: theme.colors.border },
  rowLabel: { flex: 1, fontSize: 13, fontWeight: '600', color: theme.colors.text },
  rowDetail: { fontSize: 11, color: theme.colors.textMuted, marginTop: 1 },
  rowValue: { fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  rowAction: { fontSize: 12, fontWeight: '700', color: theme.colors.primary },
  wRow: { flexDirection: 'column', paddingVertical: 10 },
  stackTrack: { height: 8, borderRadius: 4, overflow: 'hidden', flexDirection: 'row', backgroundColor: theme.colors.navyBg, marginTop: 6 },
})