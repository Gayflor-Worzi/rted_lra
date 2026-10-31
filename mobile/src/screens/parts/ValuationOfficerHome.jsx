import { View, Text, TouchableOpacity, StyleSheet } from 'react-native'
import { theme } from '../../theme'
import { Card, Badge } from '../../components'

const TYPE_LABEL = { new_property: 'New property', reassessment: 'Reassessment' }
const typeLabel = (t) => TYPE_LABEL[t] || (t || '—').replace(/_/g, ' ')

/**
 * Valuation Officer home — renders the SAME /dashboard/my payload the web
 * ValuationHome reads (valuation_work block): the officer's own 6 KPIs and a
 * "My Valuation Tasks" list that drills into the valuation detail screen.
 * Web ⇄ mobile parity comes from sharing the backend snapshot, never local math.
 */
export default function ValuationOfficerHome({ navigation, data }) {
  const work = data?.valuation_work

  if (!work) {
    return (
      <Card style={styles.block}>
        <Text style={styles.cardHeader}>Valuation Workload</Text>
        <Text style={styles.muted}>Valuation workload is not available for this account.</Text>
      </Card>
    )
  }

  const k = work.kpis
  const openWork = k.my_assigned + k.in_progress + k.returned

  const kpis = [
    { label: 'My Assigned', value: k.my_assigned, tone: theme.colors.primary, icon: '📥', order: 1 },
    { label: 'In Progress', value: k.in_progress, tone: theme.colors.warning, icon: '✍️', order: 2 },
    { label: 'Returned', value: k.returned, tone: theme.colors.danger, icon: '↩️', order: 3 },
    { label: 'Submitted', value: k.submitted, tone: theme.colors.navy, icon: '📤', order: 4 },
    { label: 'Due Today', value: k.due_today, tone: theme.colors.warning, icon: '⏰', order: 5 },
    { label: 'Completed', value: k.completed, tone: theme.colors.success, icon: '✅', order: 6 },
  ].sort((a, b) => a.order - b.order)

  return (
    <>
      <Card style={styles.block}>
        <Text style={styles.cardHeader}>My Valuation Workload</Text>
        <View style={styles.focusLine}>
          <View style={styles.focusNum}><Text style={styles.focusNumValue}>{openWork}</Text></View>
          <View style={{ flex: 1 }}>
            <Text style={styles.focusTitle}>Open work in my queue</Text>
            <Text style={styles.focusSub}>Assigned + in progress + returned for correction.</Text>
          </View>
        </View>
        <View style={styles.kpiGrid}>
          {kpis.map((s) => (
            <View key={s.label} style={styles.kpiTile}>
              <Text style={styles.kpiIcon}>{s.icon}</Text>
              <Text style={[styles.kpiValue, { color: s.tone }]}>{Number(s.value)}</Text>
              <Text style={styles.kpiLabel} numberOfLines={2}>{s.label}</Text>
            </View>
          ))}
        </View>
      </Card>

      <Card style={styles.block}>
        <View style={styles.cardHeadRow}>
          <Text style={styles.cardHeader}>My Valuation Tasks</Text>
          <TouchableOpacity onPress={() => navigation.navigate('Valuations')} hitSlop={6}>
            <Text style={styles.cardLink}>View all ›</Text>
          </TouchableOpacity>
        </View>
        {work.records.length === 0 ? (
          <Text style={styles.muted}>No valuations assigned to you yet.</Text>
        ) : (
          work.records.slice(0, 8).map((r, i) => (
            <TouchableOpacity
              key={r.id}
              onPress={() => navigation.navigate('ValuationDetail', { valuationId: r.id })}
              activeOpacity={0.7}
              style={[styles.taskRow, i > 0 && styles.rowBorder]}>
              <View style={styles.taskTop}>
                <Text style={styles.taskTitle} numberOfLines={1}>{r.valuation_reference}</Text>
                <Badge status={r.status} label={r.status} />
              </View>
              <View style={styles.taskMeta}>
                {r.property_id ? <Text style={styles.taskMetaText} numberOfLines={1}>📍 {r.property_id}</Text> : null}
                <Text style={styles.taskMetaText} numberOfLines={1}>{typeLabel(r.valuation_type)}{r.property_classification ? ` · ${r.property_classification}` : ''}</Text>
              </View>
              <Text style={styles.taskAddr} numberOfLines={1}>{r.property_address || '—'}</Text>
              <View style={styles.taskBottom}>
                <View style={{ flex: 1 }}>
                  {r.due_date ? (
                    <Text style={[styles.taskDue, r.deadline_status?.is_overdue && { color: theme.colors.danger, fontWeight: '800' }]}>
                      {r.deadline_status?.is_overdue ? `Overdue · ${r.due_date}` : `Due ${r.due_date}`}
                    </Text>
                  ) : <Text style={styles.taskDue}>No due date</Text>}
                  {r.submitted && <Text style={styles.taskSubmitted}>Submitted {r.last_action || ''}</Text>}
                </View>
                <Text style={styles.taskView}>View ›</Text>
              </View>
            </TouchableOpacity>
          ))
        )}
      </Card>
    </>
  )
}

const styles = StyleSheet.create({
  block: { marginTop: 12 },
  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 },
  cardHeadRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  cardLink: { fontSize: 13, fontWeight: '700', color: theme.colors.primary },
  muted: { color: theme.colors.textMuted, fontSize: 13 },

  focusLine: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: theme.colors.navyBg, borderRadius: 12, padding: 12, marginBottom: 12 },
  focusNum: { width: 52, height: 52, borderRadius: 26, backgroundColor: theme.colors.navy, alignItems: 'center', justifyContent: 'center' },
  focusNumValue: { color: '#fff', fontSize: 20, fontWeight: '900' },
  focusTitle: { fontSize: 13.5, fontWeight: '800', color: theme.colors.navy },
  focusSub: { fontSize: 11, color: theme.colors.textMuted, marginTop: 2 },

  kpiGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  kpiTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 9, paddingHorizontal: 6, alignItems: 'center' },
  kpiIcon: { fontSize: 16 },
  kpiValue: { fontSize: 18, fontWeight: '800', marginTop: 2 },
  kpiLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 2, fontWeight: '600', textAlign: 'center' },

  taskRow: { paddingVertical: 9 },
  taskTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  taskTitle: { flex: 1, fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  taskMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 5 },
  taskMetaText: { fontSize: 11, color: theme.colors.textMuted, fontWeight: '600' },
  taskAddr: { fontSize: 12, color: theme.colors.text, marginTop: 3 },
  taskBottom: { flexDirection: 'row', alignItems: 'center', marginTop: 5 },
  taskDue: { fontSize: 12, color: theme.colors.warning, fontWeight: '700' },
  taskSubmitted: { fontSize: 11, color: theme.colors.textMuted, marginTop: 1, fontWeight: '600' },
  taskView: { fontSize: 13, fontWeight: '700', color: theme.colors.primary, marginLeft: 8 },
  rowBorder: { borderTopWidth: 1, borderTopColor: theme.colors.border },
})