import { useCallback, useEffect, useState } from 'react'
import { Text, TouchableOpacity, View, StyleSheet } from 'react-native'
import api from '../../api'
import { theme } from '../../theme'
import { Card } from '../../components'

const label = (metric) => String(metric || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const number = (value) => Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })

export default function ManagementPerformance({ refreshKey = 0 }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/dashboard/performance/management')
      setData(response.data?.data || response.data || null)
    } catch (cause) {
      setError(cause?.response?.data?.message || 'Could not load management performance.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load, refreshKey])

  return (
    <Card style={styles.block}>
      <View style={styles.header}>
        <Text style={styles.title}>Aggregated Management Performance</Text>
        <TouchableOpacity onPress={load} disabled={loading} accessibilityRole="button" accessibilityLabel="Refresh management performance">
          <Text style={styles.refresh}>{loading ? 'Loading…' : '⟳ Refresh'}</Text>
        </TouchableOpacity>
      </View>
      {error ? (
        <Text style={styles.error}>{error}</Text>
      ) : loading ? (
        <Text style={styles.hint}>Loading management performance…</Text>
      ) : !data ? (
        <Text style={styles.hint}>Management performance is unavailable.</Text>
      ) : (
        <>
          <Text style={styles.hint}>{data.scope_label} · Approved target windows</Text>
          {data.status !== 'available' && (
            <Text style={styles.hint}>No attributable supported operational targets are available for this management scope.</Text>
          )}
          {data.attribution_limited && (
            <Text style={styles.warning}>
              Historical attribution is limited for {data.unattributed_targets_count} target window(s); only recorded assignment periods are included.
            </Text>
          )}
          {data.periods.map((period) => (
            <View key={`${period.metric}|${period.measurement_unit}|${period.start_date}|${period.end_date}`} style={styles.period}>
              <View style={styles.periodHead}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.metric}>{label(period.metric)}{period.measurement_unit ? ` (${period.measurement_unit})` : ''}</Text>
                  <Text style={styles.dates}>{period.start_date} – {period.end_date}</Text>
                </View>
                <Text style={styles.rate}>{period.completion_rate == null ? 'N/A' : `${period.completion_rate}%`}</Text>
              </View>
              <View style={styles.values}>
                <View><Text style={styles.valueLabel}>Achievement</Text><Text style={styles.value}>{number(period.achieved_value)}</Text></View>
                <View><Text style={styles.valueLabel}>Target</Text><Text style={styles.value}>{number(period.target_value)}</Text></View>
                <View><Text style={styles.valueLabel}>Staff</Text><Text style={styles.value}>{number(period.contributing_staff_count)}</Text></View>
                <View><Text style={styles.valueLabel}>Targets</Text><Text style={styles.value}>{number(period.targets_count)}</Text></View>
              </View>
              {period.status === 'no_achievements' && <Text style={styles.warning}>No eligible achievements were recorded for this target window.</Text>}
              {period.attribution_limited && <Text style={styles.warning}>Part of this target window predates recorded organizational history.</Text>}
            </View>
          ))}
        </>
      )}
      {!loading && !error && data?.unsupported_metrics?.length > 0 && (
        <Text style={styles.hint}>Not calculated because no live target rule exists: {data.unsupported_metrics.map(label).join(', ')}.</Text>
      )}
    </Card>
  )
}

const styles = StyleSheet.create({
  block: { marginTop: 12 },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 8 },
  title: { flex: 1, color: theme.colors.text, fontSize: 16, fontWeight: '800' },
  refresh: { color: theme.colors.primary, fontSize: 12, fontWeight: '800' },
  hint: { color: theme.colors.textMuted, fontSize: 12, marginBottom: 8 },
  error: { color: theme.colors.danger, fontSize: 13 },
  warning: { color: '#92400E', fontSize: 11, marginTop: 6 },
  period: { borderWidth: 1, borderColor: theme.colors.border, backgroundColor: '#F8FAFC', borderRadius: 12, padding: 12, marginTop: 8 },
  periodHead: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  metric: { color: theme.colors.text, fontWeight: '800', fontSize: 14 },
  dates: { color: theme.colors.textMuted, fontSize: 11, marginTop: 2 },
  rate: { color: theme.colors.navy, fontWeight: '900', fontSize: 17 },
  values: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 10 },
  valueLabel: { color: theme.colors.textMuted, fontSize: 10 },
  value: { color: theme.colors.text, fontWeight: '800', fontSize: 13, marginTop: 2 },
})
