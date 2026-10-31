import React from 'react'
import {
  View, Text, TouchableOpacity, ActivityIndicator, StyleSheet,
  TextInput, Image, ScrollView, KeyboardAvoidingView, Platform, useWindowDimensions, Modal,
} from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { theme } from './theme'

const LOGO = require('../assets/lra-logo.png')

export function Logo({ height = 40 }) {
  return <Image source={LOGO} style={{ height, width: height * 4.5, resizeMode: 'contain' }} />
}

const tabletStyle = (width) => ({
  width: '100%',
  maxWidth: 800,
  alignSelf: 'center',
  flex: 1,
  backgroundColor: theme.colors.bg,
})

export function Screen({ children, scroll = true, refreshControl }) {
  const { width } = useWindowDimensions()
  const insets = useSafeAreaInsets()

  if (!scroll) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors.bg }}>
        <View style={tabletStyle(width)}>{children}</View>
      </View>
    )
  }
  return (
    <View style={{ flex: 1, backgroundColor: theme.colors.bg }}>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={Platform.OS === 'ios' ? 88 : 0}
      >
        <View style={tabletStyle(width)}>
          <ScrollView
            contentContainerStyle={{ paddingBottom: 140 + insets.bottom }}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="interactive"
            refreshControl={refreshControl}
            showsVerticalScrollIndicator={false}
          >
            {children}
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </View>
  )
}

export function BrandHeader({ title, subtitle, right, subtitleNumberOfLines = 1 }) {
  const insets = useSafeAreaInsets()
  return (
    <View style={[s.brandHeader, { paddingTop: insets.top + 10 }]}>
      <View style={s.brandBar} />
      <View style={s.headerRow}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, flex: 1, minWidth: 0 }}>
          <Logo height={26} />
          {title ? (
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={s.headerTitle} numberOfLines={1}>{title}</Text>
              {subtitle && <Text style={s.headerSub} numberOfLines={subtitleNumberOfLines ?? undefined}>{subtitle}</Text>}
            </View>
          ) : null}
        </View>
        {right ? <View style={s.headerRight}>{right}</View> : null}
      </View>
    </View>
  )
}

export function Btn({ label, onPress, tone = 'primary', loading, disabled, style }) {
  const tones = {
    primary: { bg: theme.colors.primary, fg: '#fff' },
    navy: { bg: theme.colors.navy, fg: '#fff' },
    outline: { bg: '#fff', fg: theme.colors.primary, border: theme.colors.primary },
    subtle: { bg: theme.colors.primaryLight, fg: theme.colors.primaryDark },
    success: { bg: theme.colors.success, fg: '#fff' },
    danger: { bg: theme.colors.danger, fg: '#fff' },
    ghost: { bg: '#E8ECF5', fg: theme.colors.navy },
  }
  const t = tones[tone] || tones.primary
  const disabledStyle = (disabled || loading) ? { opacity: 0.6 } : {}
  return (
    <TouchableOpacity
      onPress={onPress}
      disabled={disabled || loading}
      style={[s.btn, { backgroundColor: t.bg, borderColor: t.border || 'transparent', borderWidth: t.border ? 1.5 : 0 }, disabledStyle, style]}
      activeOpacity={0.85}
      hitSlop={4}
    >
      {loading ? <ActivityIndicator color={t.fg} /> : <Text style={[s.btnText, { color: t.fg }]}>{label}</Text>}
    </TouchableOpacity>
  )
}

export function Card({ children, style, header, onPress }) {
  const Wrapper = onPress ? TouchableOpacity : View
  return (
    <Wrapper onPress={onPress} activeOpacity={0.7} style={[s.card, style]}>
      {header && <Text style={s.cardHeader}>{header}</Text>}
      {children}
    </Wrapper>
  )
}

export function Badge({ status, label }) {
  const { bg, fg } = statusBadgeSafe(status)
  return <View style={[s.badge, { backgroundColor: bg }]}><Text style={[s.badgeText, { color: fg }]}>{label || status?.replace(/_/g, ' ')}</Text></View>
}

export function OfflineReadStatus({ status, syncedAt, cacheError }) {
  if (!status) return null

  const cached = status === 'cached'
  const expired = status === 'expired'
  const message = expired
    ? 'Offline data expired. Connect and refresh.'
    : cached
      ? `Offline copy · Last synced ${new Date(syncedAt).toLocaleString()}`
      : status === 'live-not-cached'
        ? 'Live data · Not saved offline for privacy.'
      : status === 'live-cache-unavailable'
        ? 'Live data · Offline copies are unavailable on this platform.'
      : status === 'live-cache-failed'
        ? `Live data · Offline copy could not be saved${cacheError?.message ? `: ${cacheError.message}` : '.'}`
        : 'Live data'

  return (
    <Text
      accessibilityRole="text"
      style={{
        marginHorizontal: 16,
        marginTop: 6,
        marginBottom: 4,
        color: expired || status === 'live-cache-failed' ? theme.colors.danger : theme.colors.textMuted,
        fontSize: 11,
        fontWeight: '700',
      }}
    >
      {message}
    </Text>
  )
}

function statusBadgeSafe(status) {
  const map = {
    // discovery + valuation statuses (spec v4)
    draft: ['#F1F5F9', '#475569'], submitted: ['#EFF6FF', '#2563EB'],
    classified: ['#EEF2FF', '#4F46E5'],
    'Awaiting Account Manager Review': ['#FEF3C7', '#92400E'],
    'awaiting valuation': ['#CCFBF1', '#0F766E'], 'under valuation': ['#FFF7ED', '#D97706'],
    'awaiting ac approval': ['#FEF9C3', '#A16207'], approved: ['#E7F6EC', '#16A34A'],
    'returned for correction': ['#FEE2E2', '#DC2626'], 'ac returned': ['#FFF7ED', '#D97706'],
    'ac rejected': ['#FEE2E2', '#DC2626'],
    'assigned to account & record': ['#F3E8FF', '#A21CAF'],
    'referred to valuation': ['#CFFAFE', '#0E7490'],
    'registered': ['#CCFBF1', '#0F766E'], closed: ['#F1F5F9', '#475569'],
    completed: ['#E7F6EC', '#16A34A'], rejected: ['#FEE2E2', '#DC2626'],
    // valuation
    assigned: ['#EFF6FF', '#2563EB'], 'assessment in progress': ['#FFF7ED', '#D97706'],
    'submitted to manager': ['#EFF6FF', '#2563EB'], 'under manager review': ['#EEF2FF', '#4F46E5'],
    'submitted to ac': ['#F3E8FF', '#A21CAF'], 'ac approved': ['#E7F6EC', '#16A34A'],
    'sent to account & record': ['#CFFAFE', '#0E7490'],
    // tasks / bills / payments
    in_progress: ['#FFF7ED', '#D97706'], overdue: ['#FEE2E2', '#DC2626'],
    escalated: ['#FEF3C7', '#92400E'], delivered: ['#E7F6EC', '#16A34A'],
    paid: ['#E7F6EC', '#16A34A'], unpaid: ['#FEE2E2', '#DC2626'],
    pending: ['#FEF3C7', '#D97706'],
  }
  return map[status?.toLowerCase?.()] ? { bg: map[status.toLowerCase()][0], fg: map[status.toLowerCase()][1] } : { bg: '#F1F5F9', fg: '#475569' }
}

export function Field({ label, children, hint }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={s.fieldLabel}>{label}</Text>
      {children}
      {hint && <Text style={s.fieldHint}>{hint}</Text>}
    </View>
  )
}

export function Input(props) {
  return <TextInput {...props} placeholderTextColor={props.placeholderTextColor || theme.colors.textLight} style={[s.input, props.style]} />
}

export function ChipRow({ options, value, onChange, colors = {} }) {
  return (
    <View style={s.chipWrap}>
      {options.map((o) => {
        const active = o === value
        const c = colors[o] || theme.colors.primary
        return (
          <TouchableOpacity key={o} onPress={() => onChange(o)} hitSlop={3} style={[s.chip, active ? { backgroundColor: c, borderColor: c } : s.chipIdle]}>
            <Text style={[s.chipText, active ? { color: '#fff' } : { color: theme.colors.textMuted }]}>{o.replace(/_/g, ' ')}</Text>
          </TouchableOpacity>
        )
      })}
    </View>
  )
}

/**
 * Compact dropdown filter — one small button per filter, shared by the
 * officer Overview (Year / Bill status / Payment status) and the Performance
 * breakdown (Period / Metric / Group by). Keeps filter rows short and readable
 * on any screen width without horizontal scrolling of chip rows.
 *
 * options: array of display strings, or { label, value } objects.
 * value:   current selection ('' or undefined means "All").
 * onSelect receives the option's value ('' for "All").
 */
export function FilterDropdown({ label, options, value, onSelect, color }) {
  const [open, setOpen] = React.useState(false)
  const items = (options || []).map((o) => {
    if (typeof o === 'string') return { label: o, value: o }
    return { label: String((o && o.label) ?? o), value: (o && o.value) ?? String((o && o.label) ?? o) }
  })

  return (
    <>
      <TouchableOpacity onPress={() => setOpen(true)} activeOpacity={0.7} style={s.dropBtn}>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={s.dropLabel} numberOfLines={1}>{label}</Text>
          <Text style={[s.dropValue, value ? { color: color || theme.colors.navy } : null]} numberOfLines={1}>
            {value === '' || value == null ? 'All' : value}
          </Text>
        </View>
        <Text style={s.dropCaret}>▾</Text>
      </TouchableOpacity>

      <Modal visible={open} transparent animationType="fade" onRequestClose={() => setOpen(false)}>
        <TouchableOpacity style={s.dropBackdrop} activeOpacity={1} onPress={() => setOpen(false)}>
          <View style={s.dropMenu}>
            <Text style={s.dropMenuTitle}>{label}</Text>
            <ScrollView keyboardShouldPersistTaps="handled" bounces={false} style={{ maxHeight: 320 }}>
              <TouchableOpacity
                onPress={() => { onSelect(''); setOpen(false) }}
                activeOpacity={0.6}
                style={[s.dropOpt, (!value || value === '') && s.dropOptActive]}
              >
                <Text style={[s.dropOptText, (!value || value === '') && s.dropOptTextActive]}>All</Text>
              </TouchableOpacity>
              {items.map((it) => {
                const active = it.value === value
                return (
                  <TouchableOpacity
                    key={it.value}
                    onPress={() => { onSelect(it.value); setOpen(false) }}
                    activeOpacity={0.6}
                    style={[s.dropOpt, active && s.dropOptActive]}
                  >
                    <Text style={[s.dropOptText, active && s.dropOptTextActive]} numberOfLines={1}>{it.label}</Text>
                  </TouchableOpacity>
                )
              })}
            </ScrollView>
          </View>
        </TouchableOpacity>
      </Modal>
    </>
  )
}

export function Empty({ icon = '🗂', title, sub }) {
  return (
    <View style={{ alignItems: 'center', paddingVertical: 60, paddingHorizontal: 30 }}>
      <Text style={{ fontSize: 44 }}>{icon}</Text>
      <Text style={{ fontSize: 17, fontWeight: '700', color: theme.colors.text, marginTop: 14, textAlign: 'center' }}>{title}</Text>
      {sub && <Text style={{ fontSize: 13, color: theme.colors.textMuted, marginTop: 6, textAlign: 'center', lineHeight: 19 }}>{sub}</Text>}
    </View>
  )
}

export function InfoRow({ label, value, bold }) {
  return (
    <View style={s.infoRow}>
      <Text style={s.infoLabel}>{label}</Text>
      <Text style={[s.infoValue, bold && { fontWeight: '700' }]}>{value}</Text>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: theme.colors.bg },
  brandHeader: { backgroundColor: theme.colors.white, paddingBottom: 14, borderBottomWidth: 1, borderBottomColor: theme.colors.border },
  brandBar: { position: 'absolute', top: 0, left: 0, right: 0, height: 5, backgroundColor: theme.colors.primary },
  headerRow: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, paddingVertical: 8, gap: 8, minHeight: 50 },
  headerRight: { marginLeft: 8, minHeight: 44, justifyContent: 'center' },
  headerTitle: { fontSize: 17, fontWeight: '800', color: theme.colors.navy },
  headerSub: { fontSize: 12, color: theme.colors.textMuted, marginTop: 2 },
  btn: { borderRadius: 12, paddingVertical: 15, paddingHorizontal: 18, alignItems: 'center', justifyContent: 'center', flexDirection: 'row', minHeight: 50 },
  btnText: { fontSize: 15, fontWeight: '700' },
  card: { backgroundColor: '#fff', borderRadius: 14, padding: 16, marginHorizontal: 16, marginBottom: 12, borderWidth: 1, borderColor: theme.colors.border, shadowColor: '#000', shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 2 }, elevation: 1 },
  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 },
  badge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 20 },
  badgeText: { fontSize: 11, fontWeight: '700', textTransform: 'capitalize' },
  fieldLabel: { fontSize: 13, fontWeight: '600', color: theme.colors.textMuted, marginBottom: 6, paddingHorizontal: 16 },
  fieldHint: { fontSize: 11, color: theme.colors.textLight, marginTop: 4, paddingHorizontal: 16 },
  input: { backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, borderWidth: 1.5, borderColor: theme.colors.border, marginHorizontal: 16, color: theme.colors.text },
  chipWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16 },
  chip: { borderRadius: 20, paddingHorizontal: 16, paddingVertical: 11, minHeight: 42, alignItems: 'center', justifyContent: 'center' },
  chipIdle: { backgroundColor: '#fff', borderWidth: 1.5, borderColor: theme.colors.border },
  chipText: { fontSize: 13, fontWeight: '600' },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 },
  infoLabel: { fontSize: 13, color: theme.colors.textMuted },
  infoValue: { fontSize: 13, color: theme.colors.text, fontWeight: '600', maxWidth: '60%', textAlign: 'right' },
  dropRow: { flexDirection: 'row', gap: 8 },
  dropBtn: { flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 8, paddingHorizontal: 10, minHeight: 46 },
  dropLabel: { fontSize: 9, textTransform: 'uppercase', letterSpacing: 0.5, color: theme.colors.textLight, fontWeight: '800', marginBottom: 2 },
  dropValue: { fontSize: 13, fontWeight: '700', color: theme.colors.navy },
  dropCaret: { fontSize: 11, color: theme.colors.textLight, fontWeight: '800', marginBottom: 12 },
  dropBackdrop: { flex: 1, backgroundColor: 'rgba(15,23,42,0.4)', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24 },
  dropMenu: { width: '100%', maxWidth: 340, backgroundColor: '#fff', borderRadius: 14, padding: 6, shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 16, shadowOffset: { width: 0, height: 6 }, elevation: 8 },
  dropMenuTitle: { fontSize: 11, fontWeight: '800', color: theme.colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.6, paddingHorizontal: 12, paddingTop: 8, paddingBottom: 4 },
  dropOpt: { borderRadius: 10, paddingHorizontal: 12, paddingVertical: 11, minHeight: 44, justifyContent: 'center' },
  dropOptActive: { backgroundColor: theme.colors.primaryLight },
  dropOptText: { fontSize: 14, color: theme.colors.text },
  dropOptTextActive: { color: theme.colors.primaryDark, fontWeight: '700' },
})