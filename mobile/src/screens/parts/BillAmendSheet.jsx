import { useState } from 'react'
import { View, Text, TouchableOpacity, Modal, ScrollView, KeyboardAvoidingView, Platform } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../../api'
import { useAuth } from '../../auth'
import { useSync } from '../../sync'
import { useOfflineDraft } from '../../useOfflineDraft'
import { serverMessage } from '../../rbac'
import { theme } from '../../theme'
import { Btn, Badge, Input } from '../../components'

const fmtMoney = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fmtDate = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString() : '—')

const RO = ({ label, value }) => (
  <View style={st.row}>
    <Text style={st.rowLabel}>{label}</Text>
    <Text style={st.rowValue} numberOfLines={2}>{value || '—'}</Text>
  </View>
)

/**
 * Bill Amendment (mobile) — mirrors the web BillAmendModal. Only Document #,
 * Penalty and Interest are editable and only when the user holds the matching
 * permission; everything else is shown read-only. Totals recompute live, a
 * reason is recorded for audit, and full amendment history is listed below.
 * Backed by POST /property-bills/{id}/amend.
 */
export default function BillAmendSheet({ bill, onClose, onAmended, canDoc, canFin }) {
  const insets = useSafeAreaInsets()
  const { user } = useAuth()
  const { queueAndFlush } = useSync()
  const [docNum, setDocNum] = useState(bill?.document_number || '')
  const [interest, setInterest] = useState(bill?.interest_charged == null ? '' : String(bill.interest_charged))
  const [penalty, setPenalty] = useState(bill?.penalty_charged == null ? '' : String(bill.penalty_charged))
  const [reason, setReason] = useState('')
  const [history, setHistory] = useState([])
  const [err, setErr] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [maximized, setMaximized] = useState(false)
  const draftValue = { docNum, interest, penalty, reason }

  const loadHistory = async (b) => {
    try {
      const h = await api.get(`/property-bills/${b.id}/amendments`)
      setHistory(h.data?.data?.data || h.data?.data || [])
    } catch {
      setHistory([])
    }
  }

  const newTotal = (Number(bill?.tax_amount) || 0) + (Number(interest) || 0) + (Number(penalty) || 0)
  const docChanged = canDoc && docNum.trim() !== bill?.document_number
  const intChanged = canFin && Number(interest) !== Number(bill?.interest_charged ?? 0)
  const penChanged = canFin && Number(penalty) !== Number(bill?.penalty_charged ?? 0)
  const changed = docChanged || intChanged || penChanged
  const { ready: draftReady, error: draftError, clear: clearDraft } = useOfflineDraft(
    `bill-amendment:${bill?.id}`,
    user?.id,
    draftValue,
    (draft) => {
      setDocNum(draft.docNum ?? bill?.document_number ?? '')
      setInterest(draft.interest ?? (bill?.interest_charged == null ? '' : String(bill.interest_charged)))
      setPenalty(draft.penalty ?? (bill?.penalty_charged == null ? '' : String(bill?.penalty_charged)))
      setReason(draft.reason ?? '')
    },
    changed || !!reason,
  )
  const canSubmit = bill && draftReady && !busy && changed && !done

  const submit = async () => {
    setErr(''); setDone(''); setBusy(true)
    try {
      const payload = { bill_id: bill.id, reason: reason.trim() || null }
      if (docChanged) payload.document_number = docNum.trim()
      if (intChanged) payload.interest_charged = Number(interest)
      if (penChanged) payload.penalty_charged = Number(penalty)
      const result = await queueAndFlush('bill_amendment', payload)
      if (result.status !== 'failed') await clearDraft()
      const res = result.serverResponse?.bill
      if (result.status === 'synced' && res) {
        setDone(`Server confirmed the amendment for ${res.document_number} · total due ${fmtMoney(res.total_tax_due)}.`)
        onAmended && onAmended(res)
      } else {
        setDone(result.status === 'failed'
          ? 'Amendment request is in Sync with a server rejection. The original bill was not changed; review the error before resolving it.'
          : 'Amendment request is saved as Pending Sync. The original bill remains unchanged until the server validates and applies it.')
      }
    } catch (e) {
      setErr(serverMessage(e, 'Amendment failed.'))
    }
    setBusy(false)
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={st.backdrop}>
        <View style={[st.sheet, maximized && st.sheetMaximized, maximized && { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
          <View style={st.head}>
            <View style={{ flex: 1 }}>
              <Text style={st.title}>Amend bill</Text>
              <Text style={st.sub}>Document #, Penalty & Interest only · audit-trailed</Text>
            </View>
            <TouchableOpacity
              onPress={() => setMaximized((value) => !value)}
              accessibilityRole="button"
              accessibilityLabel={maximized ? 'Restore amendment form size' : 'Maximize amendment form'}
              accessibilityHint={maximized ? 'Return the form to its previous sheet size' : 'Expand the form to fill the screen'}
              hitSlop={8}
              style={st.expandBtn}
            >
              <Text style={st.expandIcon}>{maximized ? '⤡' : '⤢'}</Text>
            </TouchableOpacity>
            <TouchableOpacity onPress={onClose} hitSlop={8} style={st.closeBtn}>
              <Text style={st.closeX}>✕</Text>
            </TouchableOpacity>
          </View>

          <ScrollView style={maximized && st.expandedBody} contentContainerStyle={st.body} keyboardShouldPersistTaps="handled">
            {err ? <Text style={st.err}>{err}</Text> : null}
            {draftError ? <Text style={st.err}>{draftError}</Text> : null}
            {!draftReady ? <Text style={st.err}>Restoring encrypted amendment draft…</Text> : null}
            {done ? <Text style={st.done}>{done}</Text> : null}

            <View style={st.tagRow}>
              <Badge status={bill?.case_status} label={bill?.case_status} />
              <Badge status={bill?.payment_status} label={bill?.payment_status} />
            </View>

            <View style={st.fieldBlock}>
              <Text style={st.fieldLabel}>Document # {!canDoc ? '(read-only)' : ''}</Text>
              {canDoc ? (
                <Input value={docNum} onChangeText={setDocNum} autoCapitalize="characters" placeholder="Document number" style={st.input} />
              ) : (
                <View style={st.readOnly}><Text style={st.readOnlyText}>{bill?.document_number || '—'}</Text></View>
              )}
            </View>

            <RO label="Property ID" value={bill?.property_id} />
            <RO label="TIN" value={bill?.tin} />
            <RO label="Taxpayer" value={bill?.taxpayer_name} />
            <RO label="Property address" value={bill?.property_address} />
            <RO label="Tax amount (US$)" value={fmtMoney(bill?.tax_amount)} />

            <View style={st.fieldBlock}>
              <Text style={st.fieldLabel}>Interest charge (US$) {!canFin ? '(read-only)' : ''}</Text>
              {canFin ? (
                <Input value={interest} onChangeText={(t) => setInterest(t.replace(/[^0-9.]/g, ''))} keyboardType="decimal-pad" placeholder="0.00" style={st.input} />
              ) : (
                <View style={st.readOnly}><Text style={st.readOnlyText}>{fmtMoney(bill?.interest_charged)}</Text></View>
              )}
            </View>

            <View style={st.fieldBlock}>
              <Text style={st.fieldLabel}>Penalty charge (US$) {!canFin ? '(read-only)' : ''}</Text>
              {canFin ? (
                <Input value={penalty} onChangeText={(t) => setPenalty(t.replace(/[^0-9.]/g, ''))} keyboardType="decimal-pad" placeholder="0.00" style={st.input} />
              ) : (
                <View style={st.readOnly}><Text style={st.readOnlyText}>{fmtMoney(bill?.penalty_charged)}</Text></View>
              )}
            </View>

            <View style={st.fieldBlock}>
              <Text style={st.fieldLabel}>Reason for amendment</Text>
              <Input
                value={reason}
                onChangeText={setReason}
                placeholder="Why is this being changed? (recorded for audit)"
                multiline
                style={[st.input, { height: 72, textAlignVertical: 'top' }]}
              />
            </View>

            <View style={st.total}>
              <Text style={st.totalLabel}>Total due (recomputed)</Text>
              <Text style={st.totalValue}>{fmtMoney(newTotal)}</Text>
            </View>
            {Number(bill?.total_tax_due) !== newTotal && (
              <Text style={st.totalNote}>was {fmtMoney(bill?.total_tax_due)} · outstanding balance and payment status will be recalculated.</Text>
            )}

            <View style={st.histHead}>
              <Text style={st.histTitle}>Amendment history</Text>
              <TouchableOpacity onPress={() => loadHistory(bill)} hitSlop={6}>
                <Text style={st.histRefresh}>⟳</Text>
              </TouchableOpacity>
            </View>
            {history.length === 0 ? (
              <Text style={st.histEmpty}>No amendments yet for this bill.</Text>
            ) : (
              history.map((h) => (
                <View key={h.id} style={st.histItem}>
                  <Text style={st.histMeta}>{fmtDate(h.amended_at)} · {h.amender?.full_name || 'Unknown'}</Text>
                  {h.reason ? <Text style={st.histReason}>“{h.reason}”</Text> : null}
                  {(h.previous_document_number || h.new_document_number) && (
                    <Text style={st.histLine}>Doc# {h.previous_document_number || '—'} → {h.new_document_number || '—'}</Text>
                  )}
                  {h.previous_interest_charge != null && h.new_interest_charge != null && (
                    <Text style={st.histLine}>Interest {fmtMoney(h.previous_interest_charge)} → {fmtMoney(h.new_interest_charge)}</Text>
                  )}
                  {h.previous_penalty_charge != null && h.new_penalty_charge != null && (
                    <Text style={st.histLine}>Penalty {fmtMoney(h.previous_penalty_charge)} → {fmtMoney(h.new_penalty_charge)}</Text>
                  )}
                  <Text style={st.histLine}>Total {fmtMoney(h.previous_total_tax_due)} → {fmtMoney(h.new_total_tax_due)}</Text>
                </View>
              ))
            )}
          </ScrollView>

          <View style={st.foot}>
            <Text style={st.footHint}>{canDoc || canFin ? 'Only fields you are authorized to change are editable.' : 'You cannot amend this bill.'}</Text>
            <Btn label={busy ? 'Amending…' : changed ? 'Apply amendment' : 'Nothing to change'} onPress={submit} disabled={!canSubmit} loading={busy} style={st.submitBtn} />
          </View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  )
}

const st = {
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(15,23,42,0.45)' },
  sheet: { backgroundColor: '#fff', borderTopLeftRadius: 20, borderTopRightRadius: 20, maxHeight: '92%', minHeight: '60%' },
  sheetMaximized: { flex: 1, maxHeight: '100%', minHeight: 0, borderTopLeftRadius: 0, borderTopRightRadius: 0 },
  head: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 18, paddingVertical: 16, borderBottomWidth: 1, borderBottomColor: theme.colors.border },
  expandBtn: { minHeight: 40, minWidth: 40, alignItems: 'center', justifyContent: 'center' },
  expandIcon: { fontSize: 20, color: theme.colors.textMuted, fontWeight: '700' },
  title: { fontSize: 17, fontWeight: '800', color: theme.colors.navy },
  sub: { fontSize: 11.5, color: theme.colors.textMuted, marginTop: 2 },
  closeBtn: { minHeight: 40, minWidth: 40, alignItems: 'center', justifyContent: 'center' },
  closeX: { fontSize: 18, color: theme.colors.textLight, fontWeight: '700' },
  expandedBody: { flex: 1 },
  body: { padding: 18 },
  err: { color: theme.colors.danger, fontSize: 13, fontWeight: '600', marginBottom: 10 },
  done: { color: theme.colors.success, fontSize: 13, fontWeight: '600', marginBottom: 10 },
  tagRow: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  fieldBlock: { marginBottom: 14 },
  fieldLabel: { fontSize: 12.5, fontWeight: '600', color: theme.colors.textMuted, marginBottom: 6 },
  input: { marginHorizontal: 0 },
  readOnly: { backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingHorizontal: 12, paddingVertical: 11 },
  readOnlyText: { fontSize: 14, color: theme.colors.text, fontWeight: '600' },
  row: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 10 },
  rowLabel: { fontSize: 12, color: theme.colors.textMuted, fontWeight: '600' },
  rowValue: { fontSize: 13, color: theme.colors.text, fontWeight: '700', maxWidth: '62%', textAlign: 'right' },
  total: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: '#F1F5F9', borderRadius: 12, padding: 14, marginTop: 4 },
  totalLabel: { fontSize: 12.5, color: theme.colors.textMuted, fontWeight: '600' },
  totalValue: { fontSize: 17, fontWeight: '900', color: theme.colors.navy },
  totalNote: { fontSize: 11, color: theme.colors.textLight, marginTop: 6 },
  histHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 18, marginBottom: 8 },
  histTitle: { fontSize: 12.5, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.4 },
  histRefresh: { fontSize: 16, color: theme.colors.primary, fontWeight: '700' },
  histEmpty: { fontSize: 12.5, color: theme.colors.textLight, paddingVertical: 4 },
  histItem: { borderWidth: 1, borderColor: theme.colors.border, borderRadius: 10, padding: 10, marginBottom: 8, backgroundColor: '#fff' },
  histMeta: { fontSize: 11.5, fontWeight: '700', color: theme.colors.navy },
  histReason: { fontSize: 12, color: theme.colors.textMuted, marginTop: 3, fontStyle: 'italic' },
  histLine: { fontSize: 12, color: theme.colors.textMuted, marginTop: 2 },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: 16, borderTopWidth: 1, borderTopColor: theme.colors.border, backgroundColor: '#F8FAFC' },
  footHint: { flex: 1, fontSize: 11, color: theme.colors.textLight, marginRight: 12 },
  submitBtn: { alignSelf: 'flex-end' },
}