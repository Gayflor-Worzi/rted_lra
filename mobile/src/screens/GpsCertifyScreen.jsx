import { useCallback, useState } from 'react'
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, Linking } from 'react-native'
import * as Location from 'expo-location'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { hasAll, serverMessage } from '../rbac'
import { theme } from '../theme'
import { Screen, Card, Btn, Field, InfoRow, OfflineReadStatus } from '../components'
import { outboxById } from '../db'

const fmt = (v, d = 7) => (v === null || v === undefined ? '—' : Number(v).toFixed(d))
const dateTime = (v) => (v ? new Date(v).toLocaleString() : '—')

const STATUS = {
  pending_review: { label: 'Pending review', color: theme.colors.warning },
  approved: { label: 'Approved', color: theme.colors.success },
  rejected: { label: 'Rejected', color: theme.colors.danger },
  not_property: { label: 'Marked not the property', color: theme.colors.danger },
}

export default function GpsCertifyScreen({ navigation }) {
  const { user, canUseOfflineCache } = useAuth()
  const { queueAndFlush, retryRow } = useSync()
  const canCertify = hasAll(user, ['gps.capture', 'gps.certify'])

  const [propertyId, setPropertyId] = useState('')
  const [ctx, setCtx] = useState(null)
  const [contextSource, setContextSource] = useState(null)
  const [contextSyncedAt, setContextSyncedAt] = useState(null)
  const [verifiedProperty, setVerifiedProperty] = useState(false)
  const [loading, setLoading] = useState(false)
  const [locating, setLocating] = useState(false)
  const [permission, setPermission] = useState('not checked')
  const [gps, setGps] = useState(null)
  const [attachVisit, setAttachVisit] = useState(null)
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [messageTone, setMessageTone] = useState(theme.colors.textMuted)
  const [syncState, setSyncState] = useState(null)
  const [submissions, setSubmissions] = useState([])
  const [submissionsError, setSubmissionsError] = useState('')
  const [submissionReadStatus, setSubmissionReadStatus] = useState(null)
  const [submissionSyncedAt, setSubmissionSyncedAt] = useState(null)

  const loadSubmissions = useCallback(async (pid) => {
    if (!pid) return
    try {
      const result = await fetchOfflineRead({
        module: 'gps',
        cacheKey: `${user?.role}:submissions:${pid}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/gps/my-submissions', { params: { property_id: pid, per_page: 20 } }),
        select: (response) => response.data?.data?.data || [],
      })
      setSubmissions(result.status === 'expired' ? [] : result.data)
      setSubmissionReadStatus(result.status)
      setSubmissionSyncedAt(result.syncedAt)
      setSubmissionsError('')
    } catch (error) {
      setSubmissionsError(serverMessage(error, 'Could not load certification outcomes.'))
      setSubmissionReadStatus(null)
    }
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => {
    let active = true
    const refresh = async () => {
      if (ctx?.property_id) await loadSubmissions(ctx.property_id)
      if (syncState?.outboxId) {
        try {
          const row = await outboxById(syncState.outboxId, user?.id)
          if (active && row) setSyncState((previous) => ({
            ...previous,
            status: row.status,
            lastError: row.last_error,
          }))
        } catch {
          if (active) {
            setMessage('Could not read the saved synchronization status from this device.')
            setMessageTone(theme.colors.danger)
          }
        }
      }
    }
    refresh()
    return () => { active = false }
  }, [ctx?.property_id, loadSubmissions, syncState?.outboxId]))

  const loadContext = async () => {
    const pid = propertyId.trim()
    if (!pid) return Alert.alert('Property required', 'Enter a property ID to load its verified property record.')
    setCtx(null)
    setContextSource(null)
    setVerifiedProperty(false)
    setGps(null)
    setAttachVisit(null)
    setRemarks('')
    setSubmissions([])
    setSubmissionReadStatus(null)
    setSyncState(null)
    setMessage('')
    setLoading(true)
    try {
      const result = await fetchOfflineRead({
        module: 'gps',
        cacheKey: `${user?.role}:context:${pid}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get(`/gps/capture-context/${encodeURIComponent(pid)}`),
        select: (response) => response.data?.data,
      })
      if (result.status === 'expired') {
        setContextSource('expired')
        setContextSyncedAt(result.syncedAt)
        setMessage('The saved property context has expired. Connect and load the property again.')
        setMessageTone(theme.colors.danger)
        return
      }
      const context = result.data
      if (!context?.property_id || context.property_id !== pid) {
        throw new Error('The server did not confirm the requested property identity.')
      }
      setCtx(context)
      setContextSource(result.status)
      setContextSyncedAt(result.syncedAt)
      if (result.status === 'cached') {
        setMessage('Showing the encrypted offline copy. Connect to verify this property before capturing or submitting GPS.')
        setMessageTone(theme.colors.warning)
      }
      await loadSubmissions(pid)
    } catch (error) {
      setCtx(null)
      setContextSource(null)
      setMessage(serverMessage(error, error.message || 'Could not load this property.'))
      setMessageTone(theme.colors.danger)
    } finally {
      setLoading(false)
    }
  }

  const getGps = async () => {
    setLocating(true)
    setMessage('')
    try {
      const { status, canAskAgain } = await Location.requestForegroundPermissionsAsync()
      setPermission(status === 'granted' ? 'granted' : canAskAgain ? 'not granted' : 'denied in settings')
      if (status !== 'granted') {
        setMessage(canAskAgain
          ? 'Location permission is needed to capture GPS. Allow access and try again.'
          : 'Location permission was denied. Enable it in device settings, then retry.')
        setMessageTone(theme.colors.danger)
        return
      }
      const location = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
      setGps({
        latitude: location.coords.latitude,
        longitude: location.coords.longitude,
        accuracy: location.coords.accuracy,
        at: new Date(location.timestamp || Date.now()).toISOString(),
      })
      setSyncState(null)
      setMessage('GPS location captured. Review the property and coordinates before submitting.')
      setMessageTone(theme.colors.success)
    } catch {
      setMessage('Could not get a GPS fix. Move to a location with a clearer sky view and retry.')
      setMessageTone(theme.colors.danger)
    } finally {
      setLocating(false)
    }
  }

  const submit = (decision) => {
    if (!canCertify) return Alert.alert('Not permitted', 'Your role does not allow GPS certification.')
    if (!ctx || !contextIsLive || !verifiedProperty || ctx.property_id !== propertyId.trim()) {
      return Alert.alert('Verify property first', 'Load and confirm the displayed property before submitting a location.')
    }
    if (!gps) return Alert.alert('GPS required', 'Capture your GPS location first.')
    if (decision === 'no' && !remarks.trim()) {
      return Alert.alert('Remark required', 'Explain why this capture is not the actual property.')
    }

    Alert.alert(
      decision === 'yes' ? 'Confirm property location' : 'Confirm not the property',
      `${ctx.property_id}\n${fmt(gps.latitude)}, ${fmt(gps.longitude)}\nAccuracy: ${gps.accuracy == null ? 'unavailable' : `${gps.accuracy} m`}\nCaptured: ${dateTime(gps.at)}`,
      [
        { text: 'Review again', style: 'cancel' },
        { text: 'Confirm and submit', onPress: () => submitConfirmed(decision) },
      ],
    )
  }

  const submitConfirmed = async (decision) => {
    if (!ctx || !contextIsLive || !gps || !verifiedProperty) return
    setBusy(true)
    setMessage('Saving GPS certification locally and checking synchronization…')
    setMessageTone(theme.colors.primary)
    const payload = {
      property_id: ctx.property_id,
      certification_decision: decision,
      latitude: gps.latitude,
      longitude: gps.longitude,
      accuracy: gps.accuracy,
      source: attachVisit ? 'enforcement_visit' : 'other',
      remarks: remarks.trim() || undefined,
      captured_at: gps.at,
      ...(attachVisit ? { visit_id: attachVisit } : {}),
    }
    try {
      const result = await queueAndFlush('gps_certify', payload)
      setSyncState(result)
      if (result.status === 'synced') {
        setMessage('Submission synchronized with the server. This does not mean it is certified; check the certification status below.')
        setMessageTone(theme.colors.success)
        await loadSubmissions(ctx.property_id)
      } else if (result.status === 'failed') {
        setMessage(result.lastError || result.syncResult?.message || 'Synchronization failed. Retry from here or the Sync queue.')
        setMessageTone(theme.colors.danger)
      } else {
        setMessage('Saved on this device and queued for synchronization. The certification is not yet confirmed by the server.')
        setMessageTone(theme.colors.warning)
      }
    } catch (error) {
      setMessage(serverMessage(error, 'Could not save this GPS certification locally.'))
      setMessageTone(theme.colors.danger)
    } finally {
      setBusy(false)
    }
  }

  const retrySync = async () => {
    if (!syncState?.outboxId) return
    setBusy(true)
    try {
      await retryRow(syncState.outboxId)
      const row = await outboxById(syncState.outboxId, user?.id)
      setSyncState((previous) => ({ ...previous, status: row?.status, lastError: row?.last_error }))
      if (row?.status === 'synced') {
        setMessage('Submission synchronized with the server. This does not mean it is certified; check the certification status below.')
        setMessageTone(theme.colors.success)
        await loadSubmissions(ctx?.property_id)
      } else {
        setMessage(row?.last_error || 'Still awaiting synchronization. The record remains saved on this device.')
        setMessageTone(row?.status === 'failed' ? theme.colors.danger : theme.colors.warning)
      }
    } catch (error) {
      setMessage(serverMessage(error, 'Could not retry GPS synchronization.'))
      setMessageTone(theme.colors.danger)
    } finally {
      setBusy(false)
    }
  }

  const official = ctx?.official
  const property = ctx?.property
  const contextIsLive = contextSource === 'live' || contextSource === 'live-cache-failed'
  const activeProperty = !!ctx && contextIsLive && verifiedProperty && ctx.property_id === propertyId.trim()

  return (
    <Screen>
      <View style={styles.hero}>
        <Text style={styles.eyebrow}>FIELD GPS · LOCATION CERTIFICATION</Text>
        <Text style={styles.title}>Verify a property location</Text>
        <Text style={styles.subtitle}>Load the property, confirm its identity, capture GPS, and review before submission.</Text>
      </View>

      <View style={styles.steps}>
        {['Property', 'Capture', 'Review'].map((step, index) => {
          const complete = index === 0 ? !!activeProperty : index === 1 ? !!activeProperty && !!gps : !!activeProperty && !!gps
          return (
            <View key={step} style={styles.step}>
              <View style={[styles.stepDot, complete && styles.stepDone]}><Text style={styles.stepNumber}>{complete ? '✓' : index + 1}</Text></View>
              <Text style={[styles.stepLabel, complete && styles.stepLabelDone]}>{step}</Text>
            </View>
          )
        })}
      </View>

      {!!message && <View style={[styles.notice, { borderColor: messageTone, backgroundColor: `${messageTone}14` }]}><Text style={[styles.noticeText, { color: messageTone }]}>{message}</Text></View>}

      {!canCertify && <Card style={styles.card}><Text style={styles.dangerText}>GPS certification is not enabled for this role. Required permissions: gps.capture and gps.certify.</Text></Card>}

      <Card style={styles.card}>
        <Text style={styles.sectionTitle}>1 · Identify the property</Text>
        <Text style={styles.helper}>Enter the property ID from the assigned record. A successful lookup is required before GPS can be submitted.</Text>
        <View style={styles.lookupRow}>
          <TextInput
            style={[styles.input, { flex: 1 }]}
            placeholder="e.g. PRP-07000001"
            autoCapitalize="characters"
            value={propertyId}
            onChangeText={(value) => {
              setPropertyId(value)
              if (ctx && value.trim() !== ctx.property_id) {
                setCtx(null)
                setContextSource(null)
                setVerifiedProperty(false)
                setGps(null)
                setSubmissions([])
                setSyncState(null)
              }
            }}
            placeholderTextColor={theme.colors.textLight}
            accessibilityLabel="Property ID"
          />
          <TouchableOpacity onPress={loadContext} style={[styles.loadBtn, loading && styles.disabled]} disabled={loading}>
            {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.loadBtnText}>Load</Text>}
          </TouchableOpacity>
        </View>
        <OfflineReadStatus status={contextSource} syncedAt={contextSyncedAt} />
        {ctx && (
          <View style={styles.propertyPanel}>
            <View style={styles.propertyHeading}>
              <Text style={styles.propertyId}>{ctx.property_id}</Text>
              <Text style={styles.verifiedTag}>RECORD FOUND</Text>
            </View>
            {property ? (
              <>
                <InfoRow label="Address" value={property.address || 'Not available'} />
                <InfoRow label="Taxpayer" value={property.taxpayer_name || 'Not available'} />
                <InfoRow label="Classification" value={property.classification || 'Not available'} />
                <InfoRow label="Tax district" value={property.tax_district || 'Not available'} />
              </>
            ) : <Text style={styles.helper}>No linked property details were returned; verify the ID from the assigned record before continuing.</Text>}
            {official && (
              <InfoRow label="Existing official GPS" value={`${fmt(official.latitude)}, ${fmt(official.longitude)}`} />
            )}
            <TouchableOpacity
              onPress={() => setVerifiedProperty((current) => !current)}
              disabled={!contextIsLive}
              style={[styles.confirmProperty, verifiedProperty && styles.confirmPropertyOn]}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: verifiedProperty, disabled: !contextIsLive }}>
              <Text style={styles.confirmCheck}>{verifiedProperty ? '✓' : '○'}</Text>
              <Text style={styles.confirmText}>{contextIsLive ? 'I verified this is the property I am visiting.' : 'Connect and verify the live property before capturing GPS.'}</Text>
            </TouchableOpacity>
          </View>
        )}
      </Card>

      {activeProperty && (
        <Card style={styles.card}>
          <Text style={styles.sectionTitle}>2 · Capture GPS location</Text>
          <View style={styles.permissionRow}>
            <Text style={styles.helper}>Location permission</Text>
            <Text style={styles.permissionValue}>{permission}</Text>
          </View>
          <Btn
            tone={gps ? 'success' : 'navy'}
            label={locating ? 'Acquiring GPS…' : gps ? 'Capture again' : '📡 Capture GPS location'}
            onPress={getGps}
            loading={locating}
            disabled={!canCertify || locating}
          />
          {gps ? (
            <View style={styles.gpsPanel}>
              <Text style={styles.gpsPanelTitle}>Location captured</Text>
              <InfoRow label="Latitude" value={fmt(gps.latitude)} />
              <InfoRow label="Longitude" value={fmt(gps.longitude)} />
              <InfoRow label="Reported accuracy" value={gps.accuracy == null ? 'Unavailable from device' : `${gps.accuracy} m`} />
              <InfoRow label="Captured at" value={dateTime(gps.at)} />
              <Text style={styles.helper}>Reported accuracy is shown as supplied by the device; no agency acceptance threshold is configured.</Text>
              <TouchableOpacity
                onPress={() => Linking.openURL(`https://www.openstreetmap.org/?mlat=${gps.latitude}&mlon=${gps.longitude}#map=17/${gps.latitude}/${gps.longitude}`)}
                style={styles.mapLink}>
                <Text style={styles.mapLinkText}>View captured point on map ↗</Text>
              </TouchableOpacity>
            </View>
          ) : <Text style={styles.helper}>GPS availability: not captured. Allow location access and move to an open area if acquisition fails.</Text>}
          {ctx.open_visits?.length > 0 && (
            <View style={styles.visits}>
              <Text style={styles.fieldLabel}>Link to an open visit (optional)</Text>
              {ctx.open_visits.map((visit) => (
                <TouchableOpacity
                  key={visit.visit_id}
                  onPress={() => setAttachVisit(attachVisit === visit.visit_id ? null : visit.visit_id)}
                  style={[styles.visitRow, attachVisit === visit.visit_id && styles.visitSelected]}>
                  <Text style={styles.visitName}>{visit.visit_reference}</Text>
                  <Text style={styles.helper}>{attachVisit === visit.visit_id ? 'Selected' : 'Tap to link'}</Text>
                </TouchableOpacity>
              ))}
            </View>
          )}
        </Card>
      )}

      {activeProperty && gps && (
        <Card style={styles.card}>
          <Text style={styles.sectionTitle}>3 · Review and submit</Text>
          <View style={styles.reviewPanel}>
            <Text style={styles.reviewProperty}>{ctx.property_id}</Text>
            <Text style={styles.helper}>{property?.address || 'Property address not available'}</Text>
            <InfoRow label="Captured coordinates" value={`${fmt(gps.latitude)}, ${fmt(gps.longitude)}`} />
            <InfoRow label="Reported accuracy" value={gps.accuracy == null ? 'Unavailable' : `${gps.accuracy} m`} />
            <InfoRow label="Capture time" value={dateTime(gps.at)} />
            {attachVisit && <InfoRow label="Linked visit" value={ctx.open_visits.find((visit) => visit.visit_id === attachVisit)?.visit_reference || 'Selected visit'} />}
          </View>
          <Field label="Capture remarks">
            <TextInput
              style={[styles.input, styles.multiline]}
              multiline
              placeholder="Optional property description or observation."
              value={remarks}
              onChangeText={setRemarks}
              placeholderTextColor={theme.colors.textLight}
            />
          </Field>
          <View style={styles.decisionButtons}>
            <Btn tone="success" label="Confirm actual property" onPress={() => submit('yes')} loading={busy} disabled={busy || !canCertify} />
            <Btn tone="danger" label="Not the actual property" onPress={() => submit('no')} loading={busy} disabled={busy || !canCertify} />
          </View>
          <Text style={styles.helper}>A “yes” capture becomes official immediately only for users with update rights; otherwise it waits for authorized review.</Text>
        </Card>
      )}

      {syncState && (
        <Card style={styles.card}>
          <Text style={styles.sectionTitle}>Submission synchronization</Text>
          <Text style={[styles.syncStatus, { color: syncState.status === 'synced' ? theme.colors.success : syncState.status === 'failed' ? theme.colors.danger : theme.colors.warning }]}>
            {syncState.status === 'synced' ? 'Successfully synchronized' : syncState.status === 'failed' ? 'Synchronization failed' : syncState.status === 'syncing' ? 'Synchronizing' : 'Queued for synchronization'}
          </Text>
          {syncState.status !== 'synced' && syncState.status !== 'syncing' && syncState.outboxId && <Btn tone="navy" label="Retry synchronization" onPress={retrySync} loading={busy} disabled={busy} />}
          <TouchableOpacity onPress={() => navigation.navigate('Main', { screen: 'Sync' })} style={styles.mapLink}>
            <Text style={styles.mapLinkText}>Open Sync queue</Text>
          </TouchableOpacity>
          <Text style={styles.helper}>Synchronization confirms server receipt only. Certification is a separate review decision shown in the history below.</Text>
        </Card>
      )}

      {!!ctx && (
        <Card style={styles.card}>
          <Text style={styles.sectionTitle}>Server certification status and history</Text>
          <OfflineReadStatus status={submissionReadStatus} syncedAt={submissionSyncedAt} />
          {!!submissionsError && <Text style={styles.dangerText}>{submissionsError}</Text>}
          {!submissions.length && !submissionsError && <Text style={styles.helper}>No submissions by you for this property.</Text>}
          {submissions.map((item) => {
            const state = STATUS[item.verification_status] || { label: item.verification_status, color: theme.colors.textMuted }
            return (
              <View key={item.id} style={styles.historyRow}>
                <View style={styles.historyHeader}>
                  <Text style={styles.historyTime}>{dateTime(item.captured_at)}</Text>
                  <Text style={[styles.historyStatus, { color: state.color }]}>{state.label}</Text>
                </View>
                <Text style={styles.helper}>{fmt(item.new_latitude)}, {fmt(item.new_longitude)} · accuracy {item.accuracy == null ? 'unavailable' : `${item.accuracy} m`}</Text>
                {item.review_remarks ? <Text style={styles.reviewRemark}>Reviewer: {item.review_remarks}</Text> : null}
                {item.verification_status === 'rejected' && <Text style={styles.helper}>This submission remains in history. Capture a new location only after checking the reviewer’s reason.</Text>}
              </View>
            )
          })}
        </Card>
      )}
    </Screen>
  )
}

const styles = StyleSheet.create({
  hero: { marginHorizontal: 16, marginTop: 16, marginBottom: 8, padding: 18, borderRadius: 18, backgroundColor: theme.colors.navy },
  eyebrow: { color: '#b8c5db', fontSize: 10, fontWeight: '900', letterSpacing: 1.2 },
  title: { color: '#fff', fontSize: 22, fontWeight: '900', marginTop: 6 },
  subtitle: { color: '#d8e0ee', fontSize: 13, lineHeight: 19, marginTop: 6 },
  steps: { flexDirection: 'row', justifyContent: 'space-around', marginHorizontal: 16, marginVertical: 10 },
  step: { alignItems: 'center', gap: 5 },
  stepDot: { width: 28, height: 28, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.colors.border },
  stepDone: { backgroundColor: theme.colors.success },
  stepNumber: { color: '#fff', fontWeight: '900', fontSize: 12 },
  stepLabel: { color: theme.colors.textLight, fontSize: 11, fontWeight: '700' },
  stepLabelDone: { color: theme.colors.success },
  card: { marginHorizontal: 16, marginTop: 10, padding: 16 },
  sectionTitle: { fontSize: 15, fontWeight: '900', color: theme.colors.navy, marginBottom: 8 },
  helper: { color: theme.colors.textMuted, fontSize: 12, lineHeight: 17 },
  lookupRow: { flexDirection: 'row', gap: 8, marginTop: 12 },
  input: { backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 13, paddingVertical: 11, fontSize: 14, borderWidth: 1.5, borderColor: theme.colors.border, color: theme.colors.text, minHeight: 46 },
  multiline: { minHeight: 72, textAlignVertical: 'top', marginTop: 6 },
  loadBtn: { minWidth: 78, borderRadius: 12, backgroundColor: theme.colors.navy, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14 },
  loadBtnText: { color: '#fff', fontWeight: '900', fontSize: 14 },
  disabled: { opacity: 0.6 },
  propertyPanel: { marginTop: 14, padding: 12, borderRadius: 14, borderWidth: 1, borderColor: theme.colors.border, backgroundColor: theme.colors.bg },
  propertyHeading: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
  propertyId: { color: theme.colors.navy, fontSize: 16, fontWeight: '900' },
  verifiedTag: { color: theme.colors.success, fontSize: 9, fontWeight: '900', letterSpacing: 0.5 },
  confirmProperty: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 12, padding: 11, borderRadius: 10, backgroundColor: '#fff', borderWidth: 1, borderColor: theme.colors.border },
  confirmPropertyOn: { borderColor: theme.colors.success, backgroundColor: '#ecfdf5' },
  confirmCheck: { color: theme.colors.success, fontSize: 18, fontWeight: '900' },
  confirmText: { flex: 1, color: theme.colors.text, fontSize: 12, fontWeight: '700' },
  permissionRow: { flexDirection: 'row', justifyContent: 'space-between', marginVertical: 8 },
  permissionValue: { color: theme.colors.text, fontSize: 12, fontWeight: '800', textTransform: 'capitalize' },
  gpsPanel: { marginTop: 12, padding: 12, borderRadius: 14, backgroundColor: theme.colors.bg, borderWidth: 1, borderColor: theme.colors.border },
  gpsPanelTitle: { color: theme.colors.success, fontWeight: '900', marginBottom: 8 },
  mapLink: { marginTop: 10, paddingVertical: 8 },
  mapLinkText: { color: theme.colors.primary, fontWeight: '800', fontSize: 12 },
  visits: { marginTop: 14 },
  fieldLabel: { color: theme.colors.navy, fontSize: 11, fontWeight: '900', marginBottom: 6 },
  visitRow: { padding: 10, borderBottomWidth: 1, borderBottomColor: theme.colors.border },
  visitSelected: { backgroundColor: '#ecfdf5', borderRadius: 10, borderBottomColor: 'transparent' },
  visitName: { color: theme.colors.text, fontWeight: '800', fontSize: 13 },
  reviewPanel: { backgroundColor: theme.colors.bg, borderRadius: 14, padding: 12, marginBottom: 12 },
  reviewProperty: { color: theme.colors.navy, fontSize: 16, fontWeight: '900' },
  decisionButtons: { gap: 10, marginTop: 10 },
  syncStatus: { fontWeight: '900', fontSize: 14, marginBottom: 8 },
  historyRow: { paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: theme.colors.border },
  historyHeader: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  historyTime: { color: theme.colors.text, fontSize: 11, fontWeight: '700' },
  historyStatus: { fontSize: 11, fontWeight: '900' },
  reviewRemark: { color: theme.colors.danger, fontSize: 12, fontWeight: '700', marginTop: 5 },
  notice: { marginHorizontal: 16, marginTop: 8, padding: 12, borderRadius: 12, borderWidth: 1 },
  noticeText: { fontSize: 12, fontWeight: '700', lineHeight: 18 },
  dangerText: { color: theme.colors.danger, fontWeight: '700', fontSize: 12, lineHeight: 18 },
})
