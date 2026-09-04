import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, RefreshControl, Image, Modal } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { can, hasAny, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { Screen, Card, Badge, InfoRow, Btn, Empty, Field, Input, ChipRow } from '../components'

const fm = (v) => (v === null || v === undefined ? '—' : 'US$ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fd = (v) => (v ? String(v).slice(0, 10) : '—')

const blobToDataUri = (blob) =>
  new Promise((resolve, reject) => {
    if (blob?.data) {
      const base64 = typeof blob.data === 'string' ? blob.data : (blob.data.base64 || blob.data)
      if (base64) return resolve(`data:${blob.type || 'image/jpeg'};base64,${base64}`)
    }
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })

const ENG = {
  delivery_attempt: 'Delivery attempt', bill_delivered: 'Bill delivered', follow_up: 'Follow-up',
  reminder_30_day: '30-Day Reminder', demand_72_hour: '72-Hour Demand', final_enforcement: 'Final enforcement',
  closure: 'Closure', payment_claim: 'Payment claim', verification: 'Payment verification',
  payment_confirmed: 'Payment confirmed', assignment: 'Assignment', note: 'Note',
}

export default function TaskDetailScreen({ navigation, route }) {
  const { user } = useAuth()
  const { taskId, assignment } = route.params || {}
  const [detail, setDetail] = useState(null)
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const [busy, setBusy] = useState('')
  const [photoUris, setPhotoUris] = useState({})
  const [activePhoto, setActivePhoto] = useState(null)
  const [showEngage, setShowEngage] = useState(false)
  const [engType, setEngType] = useState('follow_up')
  const [engOutcome, setEngOutcome] = useState('')
  const [engNotes, setEngNotes] = useState('')
  const [engBusy, setEngBusy] = useState(false)

  const loadPhotoUris = useCallback(async (evidence) => {
    if (!evidence || evidence.length === 0) return
    const want = evidence.map((p) => p.id).filter((id) => !(id in photoUris))
    if (want.length === 0) return
    const next = { ...photoUris }
    await Promise.all(want.map(async (id) => {
      try {
        const r = await api.get(`/evidence/photos/${id}/download`, { responseType: 'blob' })
        next[id] = await blobToDataUri(r.data)
      } catch { next[id] = null }
    }))
    setPhotoUris(next)
  }, [photoUris])

  const evidence = detail?.evidence || []

  useFocusEffect(useCallback(() => { loadPhotoUris(evidence) }, [loadPhotoUris, evidence]))

  const load = useCallback(async () => {
    if (!taskId) { setErr('No task selected.'); return }
    try {
      const r = await api.get(`/tasks/${taskId}`)
      setDetail(r.data.data)
      setErr('')
    } catch (e) { setErr(serverMessage(e, 'Could not load task detail.')) }
  }, [taskId])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  const t = detail
  const bill = t?.bill || assignment?.property_bill || null

  const canLogVisit = can(user, 'tasks.complete') || can(user, 'enforcement.record_visit')
  const canClaim = can(user, 'payments.claim')
  const canEscalate = can(user, 'tasks.escalate')
  const canEngage = hasAny(user, ['tasks.complete', 'tasks.assign', 'tasks.escalate', 'enforcement.record_visit', 'me.view', 'payments.verify'])

  const submitEngagement = async () => {
    setEngBusy(true)
    try {
      await api.post(`/tasks/${taskId}/engagements`, {
        engagement_type: engType,
        outcome: engOutcome.trim() || undefined,
        notes: engNotes.trim() || undefined,
      })
      setShowEngage(false); setEngOutcome(''); setEngNotes('')
      Alert.alert('Engagement logged', `${ENG[engType] || engType} recorded against this task.`)
      load()
    } catch (e) {
      Alert.alert('Not logged', serverMessage(e, 'Could not record the engagement.'))
    } finally { setEngBusy(false) }
  }

  const escalate = async () => {
    if (!taskId) return
    Alert.alert('Escalate assignment', 'Return this case to the supervisor queue?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Escalate', style: 'destructive', onPress: async () => {
        setBusy('escalate')
        try {
          await api.post(`/enforcement-assignments/${taskId}/action`, { action: 'escalate', notes: 'Escalated from the field app.' })
          Alert.alert('Escalated', 'Assignment returned for review.')
          load()
        } catch (e) {
          if (!isOnlineRejection(e)) {
            Alert.alert('Saved offline', 'Escalation is queued and will sync when online.')
            load()
          } else Alert.alert('Escalation failed', serverMessage(e, 'Could not escalate.'))
        } finally { setBusy('') }
      } },
    ])
  }

  const openVisit = () => {
    const payload = t || assignment || {}
    const b = bill || { id: payload.property_bill_id, bill_number: `#${payload.property_bill_id}` }
    navigation.navigate('VisitForm', { assignment: payload, bill: b })
  }

  const openReceipt = () => {
    const b = bill || { id: t?.property_bill?.id, bill_number: t?.bill_name }
    navigation.navigate('SubmitReceipt', { bill: b })
  }

  if (err && !detail) {
    return (
      <Screen>
        <Card style={{ marginTop: 20 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load task</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{err}</Text>
          <Btn label="⟳ Retry" onPress={refresh} loading={refreshing} />
        </Card>
      </Screen>
    )
  }

  if (!detail) {
    return (
      <Screen>
        <ActivityIndicator color={theme.colors.primary} style={{ marginTop: 60 }} />
      </Screen>
    )
  }

  const na = typeof t.next_action === 'object' && t.next_action ? t.next_action : (t.next_action ? String(t.next_action) : {})
  const nextVerb = typeof na === 'string' ? na : (na.verb || na.notes || '—')
  const nextNotes = typeof na === 'object' ? (na.notes && na.notes !== nextVerb ? na.notes : null) : null

  const dl = typeof t.deadline === 'object' && t.deadline ? t.deadline : {}
  const dueDate = dl.due_date || t.due_date
  const milestone = dl.milestone

  return (
    <Screen scroll refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      {/* TASK INFORMATION */}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Text style={s.ref}>{t.task_reference}</Text>
          <Badge status={t.status} label={t.status} />
        </View>
        <InfoRow label="Task type" value={t.task_type?.replace(/_/g, ' ')} />
        <InfoRow label="Priority" value={t.priority} />
        <InfoRow label="Due date" value={fd(t.due_date)} />
        <InfoRow label="Assigned officer" value={t.assigned_to || '—'} />
        <InfoRow label="Assigned by" value={t.assigned_by || '—'} />
        {t.assignment_status && <InfoRow label="Assignment status" value={t.assignment_status} />}
        {t.stage && <InfoRow label="Stage" value={t.stage} />}
        {t.start && <InfoRow label="Started" value={fd(t.started_at)} />}
        {t.completed_at && <InfoRow label="Completed" value={fd(t.completed_at)} />}
        {t.remarks ? <InfoRow label="Remarks" value={t.remarks} /> : null}
      </Card>

      {/* CURRENT WORKFLOW */}
      <Text style={s.sectionLabel}>CURRENT WORKFLOW</Text>
      <Card>
        <InfoRow label="Previous status" value={t.previous_status || '—'} />
        <InfoRow label="Current status" value={t.status} bold />
        <InfoRow label="Next action" value={nextVerb} bold />
        {nextNotes ? <Text style={s.naNotes}>{nextNotes}</Text> : null}
        {dueDate ? <InfoRow label="Due date" value={fd(dueDate)} /> : null}
        {milestone ? <InfoRow label="Milestone" value={`${milestone.label} · ${fd(milestone.date)}${milestone.overdue ? ' (overdue)' : ''}`} /> : null}
      </Card>

      {/* PROPERTY / BILL */}
      {bill && (
        <>
          <Text style={s.sectionLabel}>PROPERTY / BILL</Text>
          <Card>
            <InfoRow label="Document #" value={bill.document_number || '—'} bold />
            <InfoRow label="Property ID" value={bill.property_id || '—'} bold />
            <InfoRow label="TIN" value={bill.tin || '—'} />
            <InfoRow label="Taxpayer" value={bill.taxpayer_name || '—'} />
            <InfoRow label="Address" value={bill.property_address || '—'} />
            <InfoRow label="Classification" value={bill.property_classification || '—'} />
            <InfoRow label="Property type" value={bill.property_type || '—'} />
            <InfoRow label="Tax period" value={bill.tax_period || '—'} />
            <InfoRow label="Recipient" value={[bill.recipient_name, bill.recipient_type].filter(Boolean).join(' · ') || '—'} />
            {bill.recipient_contact ? <InfoRow label="Recipient contact" value={bill.recipient_contact} /> : null}
          </Card>

          <Card>
            <InfoRow label="Assessed value" value={fm(bill.assessed_value)} />
            <InfoRow label="Tax amount" value={fm(bill.tax_amount)} />
            <InfoRow label="Interest" value={fm(bill.interest_charged)} />
            <InfoRow label="Penalty" value={fm(bill.penalty_charged)} />
            <InfoRow label="Total tax due" value={fm(bill.total_tax_due)} bold />
            <InfoRow label="Outstanding balance" value={fm(bill.outstanding_balance)} bold />
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
              {bill.payment_status ? <Badge label={`Payment: ${bill.payment_status}`} status={bill.payment_status} /> : null}
              {bill.delivery_status ? <Badge label={`Delivery: ${bill.delivery_status}`} status={bill.delivery_status} /> : null}
              {bill.case_status ? <Badge label={`Case: ${bill.case_status}`} status={bill.case_status} /> : null}
              {bill.escalation_stage ? <Badge label={bill.escalation_stage} status="escalated" /> : null}
            </View>
          </Card>

          <Card>
            <InfoRow label="Logged" value={fd(bill.date_logged)} />
            <InfoRow label="Delivered" value={fd(bill.delivery_date)} />
            <InfoRow label="30-day notice" value={fd(bill.thirty_day_notice_date)} />
            <InfoRow label="Final notice" value={fd(bill.final_notice_date)} />
            {(bill.account_staff || bill.enforcement_officer) ? (
              <>
                <InfoRow label="Account staff" value={bill.account_staff || '—'} />
                <InfoRow label="Enforcement officer" value={bill.enforcement_officer || '—'} />
              </>
            ) : null}
          </Card>
        </>
      )}

      {/* ENGAGEMENT HISTORY */}
      <Text style={s.sectionLabel}>ENGAGEMENT HISTORY</Text>
      {!t.engagements || t.engagements.length === 0 ? (
        <Card><Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>No engagement activity recorded yet.</Text></Card>
      ) : t.engagements.map((e) => (
        <Card key={e.id}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <Text style={s.engType}>{ENG[e.engagement_type] || e.engagement_type?.replace(/_/g, ' ')}</Text>
            {e.outcome ? <Badge label={e.outcome} /> : null}
          </View>
          {e.notes ? <Text style={s.engNote}>{e.notes}</Text> : null}
          <Text style={s.engMeta}>{e.officer || '—'} · {e.occurred_at ? new Date(e.occurred_at).toLocaleString() : ''}</Text>
        </Card>
      ))}

      {/* PAYMENTS & VERIFICATIONS */}
      {(t.payments && t.payments.length > 0) || (t.verifications && t.verifications.length > 0) ? (
        <>
          <Text style={s.sectionLabel}>PAYMENTS & VERIFICATIONS</Text>
          {t.payments && t.payments.length > 0 && (
            <Card header="Verified payments">
              {t.payments.map((p) => (
                <View key={p.id} style={s.entry}>
                  <InfoRow label="Amount" value={fm(p.amount)} bold />
                  <InfoRow label="Period" value={p.payment_period || '—'} />
                  {p.receipt_number ? <InfoRow label="Receipt #" value={p.receipt_number} /> : null}
                  {p.litas_reference ? <InfoRow label="LITAS ref" value={p.litas_reference} /> : null}
                  <Text style={s.engMeta}>{p.verified_by || '—'} · {p.verified_at ? new Date(p.verified_at).toLocaleString() : ''}</Text>
                </View>
              ))}
            </Card>
          )}
          {t.verifications && t.verifications.length > 0 && (
            <Card header="Claims & verification">
              {t.verifications.map((v) => (
                <View key={v.id} style={s.entry}>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Text style={s.engType}>Claim {fm(v.amount_claimed)}</Text>
                    <Badge label={v.verification_status} status={v.verification_status} />
                  </View>
                  <InfoRow label="Receipt #" value={v.receipt_number || '—'} />
                  {v.receipt_bill_number ? <InfoRow label="Bill on receipt" value={v.receipt_bill_number} /> : null}
                  {v.match_status ? <InfoRow label="Match" value={v.match_status} /> : null}
                  {v.rejection_reason ? <InfoRow label="Rejection reason" value={v.rejection_reason} /> : null}
                  <Text style={s.engMeta}>{v.verified_by || '—'} · {v.created_at ? new Date(v.created_at).toLocaleString() : ''}</Text>
                </View>
              ))}
            </Card>
          )}
        </>
      ) : bill ? (
        <>
          <Text style={s.sectionLabel}>PAYMENTS & VERIFICATIONS</Text>
          <Card><Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>No payment claims or verifications recorded for this bill.</Text></Card>
        </>
      ) : null}

      {/* EVIDENCE */}
      {evidence.length > 0 && (
        <>
          <Text style={s.sectionLabel}>EVIDENCE PHOTOS</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingHorizontal: 16 }}>
            {evidence.map((p) => (
              <TouchableOpacity key={p.id} style={s.thumbWrap} onPress={() => setActivePhoto(p)}>
                {photoUris[p.id]
                  ? <Image source={{ uri: photoUris[p.id] }} style={s.thumb} />
                  : <View style={[s.thumb, { alignItems: 'center', justifyContent: 'center' }]}><ActivityIndicator color={theme.colors.primary} /></View>}
                <Text style={s.thumbCap} numberOfLines={1}>{p.photo_reference}</Text>
              </TouchableOpacity>
            ))}
          </View>
        </>
      )}

      {/* STATUS HISTORY */}
      {t.history && t.history.length > 0 ? (
        <>
          <Text style={s.sectionLabel}>STATUS HISTORY</Text>
          {t.history.map((h, i) => (
            <Card key={i}>
              <Text style={s.engType}>{h.from_status || '—'} → {h.to_status}</Text>
              <Text style={s.engNote}>{h.action || h.remarks || ''}{h.remarks && h.action ? ` — ${h.remarks}` : ''}</Text>
              <Text style={s.engMeta}>{h.performed_by || '—'} · {h.created_at ? new Date(h.created_at).toLocaleString() : ''}</Text>
            </Card>
          ))}
        </>
      ) : null}

      {/* ACTIONS */}
      <View style={{ flexDirection: 'row', gap: 8, paddingHorizontal: 16, marginTop: 4 }}>
        {canLogVisit && (
          <TouchableOpacity style={[s.actionBtn, { backgroundColor: theme.colors.primary }]} onPress={openVisit}>
            <Text style={s.actionText}>Record Field Visit</Text>
          </TouchableOpacity>
        )}
        {canClaim && (
          <TouchableOpacity style={[s.actionBtn, { backgroundColor: theme.colors.navy }]} onPress={openReceipt}>
            <Text style={s.actionText}>Submit Receipt</Text>
          </TouchableOpacity>
        )}
      </View>
      {canEngage && (
        <Btn tone="navy" label="🗒 Log engagement" onPress={() => setShowEngage(!showEngage)} style={{ marginTop: 10 }} />
      )}

      {showEngage && (
        <Card style={{ marginTop: 10 }}>
          <Text style={s.panelTitle}>Log an engagement</Text>
          <Text style={s.panelSub}>Record a follow-up, reminder, demand or note without a full field visit.</Text>
          <View style={{ paddingHorizontal: 0, marginBottom: 10 }}>
            <ChipRow options={Object.keys(ENG).filter((k) => ENG[k])} value={engType} onChange={setEngType} />
          </View>
          <Field label="Outcome (optional)">
            <Input value={engOutcome} onChangeText={setEngOutcome} placeholder="e.g. promised_payment, no_answer, contact_made" />
          </Field>
          <Field label="Notes">
            <Input value={engNotes} onChangeText={setEngNotes} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="What happened…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setShowEngage(false)} disabled={engBusy} />
            <Btn label="Log it" style={{ flex: 1 }} onPress={submitEngagement} loading={engBusy} />
          </View>
        </Card>
      )}

      {canEscalate && !['Resolved', 'Closed', 'Paid', 'Escalated'].includes(t.status) && (
        <Btn tone="danger" label="⤴ Escalate to supervisor" onPress={escalate} loading={busy === 'escalate'} style={{ marginTop: 10 }} />
      )}

      {/* PHOTO LIGHTBOX */}
      <Modal visible={!!activePhoto} transparent animationType="fade" onRequestClose={() => setActivePhoto(null)}>
        <View style={s.lightbox}>
          {activePhoto && photoUris[activePhoto.id]
            ? <Image source={{ uri: photoUris[activePhoto.id] }} style={s.lightboxImg} resizeMode="contain" />
            : <ActivityIndicator color="#fff" />}
          {activePhoto && <Text style={s.lightboxCap}>{activePhoto.photo_reference} · {activePhoto.photo_type?.replace(/_/g, ' ')}</Text>}
          <TouchableOpacity style={s.lightboxClose} onPress={() => setActivePhoto(null)}>
            <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15 }}>✕ Close</Text>
          </TouchableOpacity>
        </View>
      </Modal>
    </Screen>
  )
}

const s = StyleSheet.create({
  ref: { fontSize: 16, fontWeight: '800', color: theme.colors.navy, flexShrink: 1 },
  sectionLabel: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, letterSpacing: 0.8, marginLeft: 16, marginTop: 20, marginBottom: 10 },
  naNotes: { fontSize: 13, color: theme.colors.textMuted, marginTop: 6, lineHeight: 18 },
  engType: { fontSize: 14, fontWeight: '700', color: theme.colors.navy, flexShrink: 1 },
  engNote: { fontSize: 13, color: theme.colors.text, marginTop: 4, lineHeight: 18 },
  engMeta: { fontSize: 11, color: theme.colors.textLight, marginTop: 4 },
  actionBtn: { flex: 1, borderRadius: 10, paddingVertical: 14, alignItems: 'center', minHeight: 48, justifyContent: 'center' },
  actionText: { color: '#fff', fontWeight: '800', fontSize: 13 },
  entry: { paddingBottom: 12, marginBottom: 12, borderBottomWidth: 1, borderBottomColor: theme.colors.border || '#eee' },
  panelTitle: { fontSize: 14, fontWeight: '800', color: theme.colors.navy, marginBottom: 4 },
  panelSub: { fontSize: 12, color: theme.colors.textMuted, marginBottom: 12 },
  thumbWrap: { width: 96, marginBottom: 8 },
  thumb: { width: 96, height: 96, borderRadius: 10, backgroundColor: theme.colors.surface || '#eef1f5' },
  thumbCap: { fontSize: 10, color: theme.colors.textMuted, marginTop: 4, width: 96 },
  lightbox: { flex: 1, backgroundColor: 'rgba(0,0,0,0.93)', alignItems: 'center', justifyContent: 'center', padding: 24 },
  lightboxImg: { width: '100%', height: '70%' },
  lightboxCap: { color: '#fff', fontSize: 13, marginTop: 14, textAlign: 'center' },
  lightboxClose: { position: 'absolute', top: 60, right: 20, padding: 10 },
})