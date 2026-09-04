// Lightweight mobile chart primitives — one bar chart and one donut chart,
// no chart library. Donut uses react-native-svg (bundled in Expo Go); the bar
// list is pure View/Text so it stays fast and touch-friendly.

import React from 'react'
import { View, Text, TouchableOpacity, ScrollView } from 'react-native'
import Svg, { Path } from 'react-native-svg'
import { theme } from './theme'

export const CHART_COLORS = [
  '#C70000', '#002060', '#0EA5E9', '#10B981', '#F59E0B',
  '#8B5CF6', '#EC4899', '#14B8A6', '#F43F5E', '#64748B',
]

export const colorAt = (i, data) => (data[i]?.color) || CHART_COLORS[i % CHART_COLORS.length]

const fmtN = (v) => {
  const n = Number(v)
  if (Number.isNaN(n)) return '0'
  return Math.abs(n) >= 1000 ? `${(n / 1000).toFixed(1)}k` : `${Math.round(n)}`
}

const polar = (cx, cy, r, deg) => ({
  x: cx + r * Math.cos(((deg - 90) * Math.PI) / 180),
  y: cy + r * Math.sin(((deg - 90) * Math.PI) / 180),
})

function segment(cx, cy, r1, r2, start, end) {
  const large = end - start <= 180 ? 0 : 1
  const p1 = polar(cx, cy, r1, end)
  const p2 = polar(cx, cy, r2, end)
  const p3 = polar(cx, cy, r2, start)
  const p4 = polar(cx, cy, r1, start)
  return `M ${p1.x} ${p1.y} A ${r1} ${r1} 0 ${large} 1 ${p4.x} ${p4.y} L ${p3.x} ${p3.y} A ${r2} ${r2} 0 ${large} 0 ${p2.x} ${p2.y} Z`
}

export function Donut({ data = [], size = 200, thickness = 30, center = 'total', onSlice }) {
  const items = (data || []).filter((d) => d && Number(d.value) > 0)
  const total = items.reduce((s, d) => s + Number(d.value), 0)
  if (total <= 0) {
    return <Text style={styles.empty}>No data to chart.</Text>
  }

  const cx = size / 2
  const cy = size / 2
  const r1 = size / 2 - thickness
  const r2 = size / 2 - thickness / 2
  let angle = 0

  return (
    <View>
      <View style={{ alignItems: 'center' }}>
        <Svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
          {items.map((d, i) => {
            const slice = (Number(d.value) / total) * 360
            const path = segment(cx, cy, r1, r2, angle, angle + slice)
            angle += slice
            return (
              <Path
                key={`${d.label}-${i}`}
                d={path}
                fill={colorAt(i, items)}
                stroke="#fff"
                strokeWidth={1.5}
                onPress={onSlice ? () => onSlice(d) : undefined}
              />
            )
          })}
        </Svg>
        <View style={styles.donutCenter}>
          <Text style={styles.donutTotal}>{fmtN(total)}</Text>
          <Text style={styles.donutLabel}>{center}</Text>
        </View>
      </View>
      <View style={styles.legend}>
        {items.map((d, i) => (
          <TouchableOpacity
            key={`${d.label}-${i}`}
            disabled={!onSlice}
            onPress={onSlice ? () => onSlice(d) : undefined}
            style={styles.legendRow}
            hitSlop={4}
          >
            <View style={[styles.legendDot, { backgroundColor: colorAt(i, items) }]} />
            <Text style={styles.legendText} numberOfLines={1}>{d.label}</Text>
            <Text style={styles.legendValue}>
              {fmtN(d.value)}<Text style={styles.legendPct}> · {Math.round((Number(d.value) / total) * 100)}%</Text>
            </Text>
          </TouchableOpacity>
        ))}
      </View>
    </View>
  )
}

/** Ranked horizontal bars. data: [{ label, value, color? }] */
export function HBars({ data = [], format, onPress }) {
  const items = [...(data || [])]
    .filter((d) => d && Number(d.value) > 0)
    .sort((a, b) => Number(b.value) - Number(a.value))
    .slice(0, 8)

  if (items.length === 0) {
    return <Text style={styles.empty}>No data in range.</Text>
  }

  const max = Math.max(...items.map((d) => Number(d.value)), 1)
  const fmt = format || fmtN

  return (
    <View>
      {items.map((d, i) => (
        <TouchableOpacity key={`${d.label}-${i}`} disabled={!onPress} onPress={onPress ? () => onPress(d) : undefined} activeOpacity={0.7} style={styles.barRow} hitSlop={4}>
          <View style={styles.barHead}>
            <Text style={styles.barLabel} numberOfLines={1}>{d.label}</Text>
            <Text style={styles.barValue}>{fmt(d.value)}</Text>
          </View>
          <View style={styles.barTrack}>
            <View style={[styles.barFill, { width: `${Math.max((Number(d.value) / max) * 100, 3)}%`, backgroundColor: colorAt(i, items) }]} />
          </View>
        </TouchableOpacity>
      ))}
    </View>
  )
}

/** Target vs achieved comparison bars. data: [{ label, achieved, target }] */
export function GroupedBars({ data = [] }) {
  const items = (data || []).slice(0, 6)
  if (items.length === 0) {
    return <Text style={styles.empty}>No data to chart.</Text>
  }
  const max = Math.max(...items.flatMap((d) => [Number(d.achieved) || 0, Number(d.target) || 0]), 1)

  return (
    <View>
      {items.map((d, i) => {
        const a = Number(d.achieved) || 0
        const t = Number(d.target) || 0
        return (
          <View key={`${d.label}-${i}`} style={styles.barRow}>
            <View style={styles.barHead}>
              <Text style={styles.barLabel} numberOfLines={1}>{d.label}</Text>
              <Text style={styles.barValue}>{fmtN(a)} / {fmtN(t)}</Text>
            </View>
            <View style={styles.barTrack}>
              <View style={[styles.barFill, { width: `${(t / max) * 100}%`, backgroundColor: '#94A3B8', opacity: 0.55 }]} />
            </View>
            <View style={styles.barTrack}>
              <View style={[styles.barFill, { width: `${(a / max) * 100}%`, backgroundColor: theme.colors.success }]} />
            </View>
          </View>
        )
      })}
      <View style={styles.legendInline}>
        <View style={styles.legendInlineItem}>
          <View style={[styles.legendDot, { backgroundColor: theme.colors.success }]} />
          <Text style={styles.legendText}>Achieved</Text>
        </View>
        <View style={styles.legendInlineItem}>
          <View style={[styles.legendDot, { backgroundColor: '#94A3B8' }]} />
          <Text style={styles.legendText}>Target</Text>
        </View>
      </View>
    </View>
  )
}

/** Filter chips that scroll horizontally (touch-friendly). */
export function HScrollChips({ options, value, onChange, colors }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}>
      {options.map((o) => {
        const active = o === value
        const c = colors?.[o] || theme.colors.primary
        return (
          <TouchableOpacity key={o} onPress={() => onChange(o)} hitSlop={4} style={[styles.chip, active ? { backgroundColor: c, borderColor: c } : styles.chipIdle]}>
            <Text style={[styles.chipText, active ? { color: '#fff' } : { color: theme.colors.textMuted }]}>{o.replace(/_/g, ' ')}</Text>
          </TouchableOpacity>
        )
      })}
    </ScrollView>
  )
}

const styles = {
  empty: { color: theme.colors.textLight, fontSize: 13, paddingVertical: 8 },
  donutCenter: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center' },
  donutTotal: { fontSize: 26, fontWeight: '800', color: theme.colors.navy },
  donutLabel: { fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.6, color: theme.colors.textLight, fontWeight: '700' },
  legend: { marginTop: 12, gap: 6 },
  legendRow: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 4 },
  legendDot: { width: 10, height: 10, borderRadius: 5, marginRight: 2 },
  legendText: { flex: 1, fontSize: 13, color: theme.colors.text, fontWeight: '500' },
  legendValue: { fontSize: 13, fontWeight: '700', color: theme.colors.navy },
  legendPct: { fontSize: 11, color: theme.colors.textLight, fontWeight: '500' },
  legendInline: { flexDirection: 'row', gap: 18, marginTop: 10, alignItems: 'center' },
  legendInlineItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  barRow: { paddingVertical: 6 },
  barHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 4 },
  barLabel: { flex: 1, fontSize: 13, color: theme.colors.text, fontWeight: '500' },
  barValue: { fontSize: 13, fontWeight: '700', color: theme.colors.navy },
  barTrack: { height: 8, borderRadius: 6, backgroundColor: '#F1F5F9', overflow: 'hidden', marginBottom: 4 },
  barFill: { height: '100%', borderRadius: 6 },
  chip: { borderRadius: 20, paddingHorizontal: 16, paddingVertical: 11, minHeight: 42, alignItems: 'center', justifyContent: 'center' },
  chipIdle: { backgroundColor: '#fff', borderWidth: 1.5, borderColor: theme.colors.border },
  chipText: { fontSize: 13, fontWeight: '600' },
}