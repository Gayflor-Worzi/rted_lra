import { useState } from 'react'
import { View, Text, TextInput, StyleSheet, ScrollView, Alert, ActivityIndicator, Image, TouchableOpacity, useWindowDimensions, KeyboardAvoidingView, Platform } from 'react-native'
import * as Location from 'expo-location'
import * as ImagePicker from 'expo-image-picker'
import api from '../api'
import { useAuth } from '../auth'
import { can } from '../rbac'
import { theme } from '../theme'
import { Field, Btn, ChipRow, Card, Empty } from '../components'

const rowValue = (r) => (Number(r.amount) || 0) * (Number(r.quantity) || 0) * (1 - (Number(r.depreciation_pct) || 0) / 100)
const emptyRow = () => ({ description: '', level: 'Ground Floor', area_sqft: '', tar: '', quantity: '1', amount: '', building_age: '', depreciation_pct: '' })

const PROPERTY_CLASSIFICATIONS = [
  'Residential', 'Commercial', 'Industrial', 'Unimproved Land',
  'Residential Building on public land', 'Commercial Building on public land',
  'Developed Land Residential', 'Vacant land', 'Mixed Use',
]

const LEVELS = ['Ground Floor', '1st Floor', '2nd Floor', '3rd Floor', 'Basement', 'Exterior', 'Other']

const SUMMARY_FIELDS = ['level', 'area_sqft', 'tar', 'quantity', 'amount', 'building_age', 'depreciation_pct']
const SUM_LABELS = { level: 'Level', area_sqft: 'Area (sq ft)', tar: 'TAR', quantity: 'Qty', amount: 'Amount (US$)', building_age: 'Age (yrs)', depreciation_pct: 'Depr. (%)' }

export default function NewValuationScreen({ navigation }) {
  const { user } = useAuth()
  const { width } = useWindowDimensions()
  const [mode, setMode] = useState('new_property')
  const [owner, setOwner] = useState('')
  const [ownerContact, setOwnerContact] = useState('')
  const [tin, setTin] = useState('')
  const [address, setAddress] = useState('')
  const [classification, setClassification] = useState('')
  const [landDims, setLandDims] = useState('')
  const [buildingSpecs, setBuildingSpecs] = useState('')
  const [constructionYear, setConstructionYear] = useState('')
  const [condition, setCondition] = useState('')
  const [assessmentDate, setAssessmentDate] = useState('')
  const [declaredValue, setDeclaredValue] = useState('')
  const [taxRate, setTaxRate] = useState('')
  const [reassessed, setReassessed] = useState('')
  const [otherAmounts, setOtherAmounts] = useState('')
  const [remarks, setRemarks] = useState('')
  const [billRef, setBillRef] = useState('')
  const [propertyRef, setPropertyRef] = useState('')
  const [docRef, setDocRef] = useState('')
  const [gps, setGps] = useState(null)
  const [locating, setLocating] = useState(false)
  const [rows, setRows] = useState([emptyRow()])
  const [photos, setPhotos] = useState([])
  const [busy, setBusy] = useState(false)

  if (!can(user, 'valuation.create')) {
    return (
      <View style={s.container}>
        <Empty icon="🚫" title="No access" sub="Your role cannot create valuations." />
      </View>
    )
  }

  const setRowAt = (i, patch) => setRows(rows.map((r, ix) => (ix === i ? { ...r, ...patch } : r)))

  const getGps = async () => {
    setLocating(true)
    try {
      const { status, canAskAgain } = await Location.requestForegroundPermissionsAsync()
      if (status !== 'granted') {
        return Alert.alert(
          'Location access needed',
          canAskAgain
            ? 'Grant location permission to stamp the assessment with GPS.'
            : 'Location permission was denied. Enable it in your device settings, then try again.'
        )
      }
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
      setGps({ lat: loc.coords.latitude, lng: loc.coords.longitude, accuracy: loc.coords.accuracy != null ? Math.round(loc.coords.accuracy) : null })
    } catch { Alert.alert('GPS error', 'Could not get a location fix. Try again.') }
    finally { setLocating(false) }
  }

  const takePhoto = async () => {
    const { status, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (status !== 'granted') {
      return Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission for property evidence.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
    }
    const res = await ImagePicker.launchCameraAsync({ quality: 0.7 })
    if (!res.canceled) setPhotos([...photos, res.assets[0].uri])
  }

  const total = rows.reduce((sum, r) => sum + rowValue(r), 0)
  const annualTax = (Number(reassessed) || 0) > 0 && (Number(taxRate) || 0) > 0
    ? (Number(reassessed) * (Number(taxRate) / 100)) + (Number(otherAmounts) || 0)
    : null

  const submit = async () => {
    if (!owner.trim() || !address.trim()) return Alert.alert('Missing fields', 'Owner name and property address are required.')
    setBusy(true)
    const descriptions = rows
      .filter((r) => r.description.trim() || Number(r.amount) > 0)
      .map((r) => ({
        description: r.description.trim() || undefined,
        level: r.level.trim() || undefined,
        area_sqft: r.area_sqft.trim() ? Number(r.area_sqft) : undefined,
        tar: r.tar.trim() ? Number(r.tar) : undefined,
        quantity: Number(r.quantity) || 1,
        amount: Number(r.amount) || undefined,
        building_age: r.building_age.trim() ? Number(r.building_age) : undefined,
        depreciation_pct: Number(r.depreciation_pct) || undefined,
      }))
    try {
      const r = await api.post('/valuations', {
        valuation_type: mode,
        bill_id: billRef.trim() ? Number(billRef) : undefined,
        property_id: propertyRef.trim() || undefined,
        document_number: docRef.trim() || undefined,
        owner_name: owner.trim(),
        owner_contact: ownerContact.trim() || undefined,
        tin: tin.trim() || undefined,
        property_address: address.trim(),
        property_classification: classification.trim() || undefined,
        land_dimensions: landDims.trim() || undefined,
        building_specs: buildingSpecs.trim() || undefined,
        construction_year: constructionYear.trim() ? Number(constructionYear) : undefined,
        condition: condition.trim() || undefined,
        assessment_date: assessmentDate || undefined,
        declared_value: declaredValue.trim() ? Number(declaredValue) : undefined,
        applicable_tax_rate: taxRate.trim() ? Number(taxRate) : undefined,
        reassessed_value: reassessed.trim() ? Number(reassessed) : undefined,
        other_amounts: otherAmounts.trim() ? Number(otherAmounts) : undefined,
        remarks: remarks.trim() || undefined,
        gps_coordinate: gps ? `${gps.lat.toFixed(6)},${gps.lng.toFixed(6)}` : undefined,
        gps_accuracy: gps?.accuracy,
        descriptions,
      })
      const draft = r.data.data

      if (photos.length > 0) {
        await api.post('/evidence/photos', {
          photo_type: 'PROPERTY_FULL_VIEW',
          valuation_id: draft.id,
          property_id: draft.property_id || undefined,
          path: photos[0],
          gps_coordinate: gps ? `${gps.lat.toFixed(6)},${gps.lng.toFixed(6)}` : undefined,
        })
      }

      Alert.alert('Draft saved', `Valuation ${draft.valuation_reference} created. Review the totals, then submit.`, [
        { text: 'OK', onPress: () => navigation.goBack() },
      ])
    } catch (e) {
      const msg = e.response?.data?.message || e.message
      Alert.alert('Could not save', msg)
    } finally { setBusy(false) }
  }

  return (
    <View style={s.container}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={Platform.OS === 'ios' ? 88 : 0}>
        <ScrollView
          contentContainerStyle={{ paddingBottom: 140, width: '100%', maxWidth: 800, alignSelf: 'center' }}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
        >
          <Card header="New field assessment">
            <Text style={s.label}>Valuation type</Text>
            <ChipRow options={['new_property', 'reassessment']} value={mode} onChange={setMode} />

            <View style={{ height: 14 }} />
            <Field label="Source reference (Bill ID — if reassessing)">
              <TextInput style={s.input} keyboardType="numeric" placeholder="Linked property bill id, e.g. 42" value={billRef} onChangeText={setBillRef} />
            </Field>
            <Field label="Property ID">
              <TextInput style={s.input} placeholder="LITAS property id (from discovery / bill)" value={propertyRef} onChangeText={setPropertyRef} />
            </Field>
            <Field label="Document #">
              <TextInput style={s.input} placeholder="LITAS document number" value={docRef} onChangeText={setDocRef} />
            </Field>

            <Text style={s.label}>Taxpayer</Text>
            <Field label="Owner name">
              <TextInput style={s.input} placeholder="Property owner" value={owner} onChangeText={setOwner} />
            </Field>
            <Field label="Owner contact">
              <TextInput style={s.input} placeholder="Phone / email" value={ownerContact} onChangeText={setOwnerContact} />
            </Field>
            <Field label="TIN">
              <TextInput style={s.input} placeholder="Tax Identification Number" value={tin} onChangeText={setTin} />
            </Field>

            <Text style={s.label}>Property</Text>
            <Field label="Property address">
              <TextInput style={s.input} placeholder="Street / community / county" value={address} onChangeText={setAddress} />
            </Field>
            <Field label="Classification">
              <ChipRow options={PROPERTY_CLASSIFICATIONS} value={classification} onChange={setClassification} />
            </Field>
            <Field label="Land dimensions">
              <TextInput style={s.input} placeholder="e.g. 40ft × 60ft" value={landDims} onChangeText={setLandDims} />
            </Field>
            <Field label="Building specs">
              <TextInput style={s.input} placeholder="Structure, floors, material…" value={buildingSpecs} onChangeText={setBuildingSpecs} />
            </Field>
            <Field label="Year constructed">
              <TextInput style={s.input} keyboardType="numeric" placeholder="e.g. 2005" value={constructionYear} onChangeText={setConstructionYear} />
            </Field>
            <Field label="Condition">
              <TextInput style={s.input} placeholder="e.g. Good, Fair, Poor" value={condition} onChangeText={setCondition} />
            </Field>
            <Field label="Assessment date (YYYY-MM-DD)">
              <TextInput style={s.input} placeholder="2025-01-01" value={assessmentDate} onChangeText={setAssessmentDate} />
            </Field>

            <Field label="GPS location">
              <Btn tone={gps ? 'success' : 'navy'} label={gps ? `✓ ${gps.lat.toFixed(5)}, ${gps.lng.toFixed(5)}${gps.accuracy ? ` (±${gps.accuracy}m)` : ''}` : '📡 Capture GPS'} onPress={getGps} loading={locating} />
            </Field>

            <Field label="Evidence photo">
              <View style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
                <Btn tone="outline" label="📷 Camera" onPress={takePhoto} style={{ flex: 1 }} />
                {photos.length > 0 && <Image source={{ uri: photos[0] }} style={{ width: 56, height: 56, borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border }} />}
              </View>
            </Field>

            <Text style={s.label}>Property description & valuation sheet</Text>
            {rows.map((r, i) => (
              <View key={i} style={s.rowCard}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                  <Text style={s.rowNo}>No. {i + 1}</Text>
                  {rows.length > 1 && (
                    <TouchableOpacity hitSlop={6} onPress={() => setRows(rows.filter((_, ix) => ix !== i))}
                      style={{ paddingVertical: 6, paddingHorizontal: 8, minHeight: 36, justifyContent: 'center' }}>
                      <Text style={{ color: theme.colors.danger, fontWeight: '700' }}>Remove</Text>
                    </TouchableOpacity>
                  )}
                </View>
                <TextInput style={s.input} placeholder="Property description (e.g. 3-bedroom house)" value={r.description} onChangeText={(v) => setRowAt(i, { description: v })} />
                <View style={{ marginTop: 10 }}>
                  <Text style={s.cellLabel}>Level</Text>
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
                    {LEVELS.map((l) => {
                      const active = r.level === l
                      return (
                        <TouchableOpacity key={l} onPress={() => setRowAt(i, { level: l })}
                          hitSlop={3} style={[s.levelChip, active ? { backgroundColor: theme.colors.navy, borderColor: theme.colors.navy } : s.levelChipIdle]}>
                          <Text style={[s.levelChipText, active ? { color: '#fff' } : { color: theme.colors.textMuted }]}>{l}</Text>
                        </TouchableOpacity>
                      )
                    })}
                  </View>
                </View>
                <View style={{ flexDirection: 'row', gap: 8, marginTop: 10 }}>
                  {SUMMARY_FIELDS.filter((f) => f !== 'level').map((f) => (
                    <View key={f} style={{ flex: 1 }}>
                      <Text style={s.cellLabel}>{SUM_LABELS[f]}</Text>
                      <TextInput
                        style={[s.input, { paddingHorizontal: 8, paddingVertical: 9, fontSize: 13 }]}
                        keyboardType="numeric"
                        placeholder={f === 'amount' || f === 'depreciation_pct' ? '—' : f === 'tar' ? 'rate' : ''}
                        value={r[f]}
                        onChangeText={(v) => setRowAt(i, { [f]: v })}
                      />
                    </View>
                  ))}
                </View>
                <Text style={{ fontSize: 12, color: theme.colors.textMuted, marginTop: 8 }}>
                  This row value = Amount × Qty × (1 − Depreciation%) →{' '}
                  <Text style={{ fontWeight: '800', color: theme.colors.navy }}>US$ {rowValue(r).toLocaleString()}</Text>
                </Text>
              </View>
            ))}
            <Btn tone="ghost" label="+ Add Row" onPress={() => setRows([...rows, emptyRow()])} style={{ marginTop: 4 }} />

            <View style={s.totalsCard}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={s.totalLabel}>Total computed value</Text>
                <Text style={s.totalValue}>US$ {total.toLocaleString()}</Text>
              </View>
              <Text style={s.hint}>Annual tax = Reassessed value × Rate% {Number(otherAmounts) > 0 ? '+ Other amounts' : ''}</Text>
            </View>
            <Field label="Declared value (US$)">
              <TextInput style={s.input} keyboardType="numeric" placeholder="Owner declared value" value={declaredValue} onChangeText={setDeclaredValue} />
            </Field>
            <Field label="Reassessed value (US$)">
              <TextInput style={s.input} keyboardType="numeric" placeholder="Assessed value" value={reassessed} onChangeText={setReassessed} />
            </Field>
            <Field label="Applicable tax rate (%)">
              <TextInput style={s.input} keyboardType="numeric" placeholder="e.g. 0.5" value={taxRate} onChangeText={setTaxRate} />
            </Field>
            <Field label="Other amounts (US$)">
              <TextInput style={s.input} keyboardType="numeric" placeholder="Fees, surcharges…" value={otherAmounts} onChangeText={setOtherAmounts} />
            </Field>
            {annualTax != null && (
              <View style={s.totalsCard}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={s.totalLabel}>Annual tax payable (preview)</Text>
                  <Text style={s.totalValue}>US$ {annualTax.toLocaleString()}</Text>
                </View>
              </View>
            )}
            <Field label="Remarks">
              <TextInput style={[s.input, { minHeight: 60, textAlignVertical: 'top' }]} multiline placeholder="Optional" value={remarks} onChangeText={setRemarks} />
            </Field>

            <Btn label={busy ? 'Saving…' : '✓ Save draft'} onPress={submit} loading={busy} style={{ marginTop: 14 }} />
          </Card>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  label: { fontSize: 12, fontWeight: '700', color: theme.colors.textMuted, marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.5 },
  input: { backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, borderWidth: 1.5, borderColor: theme.colors.border, color: theme.colors.text },
  rowCard: { backgroundColor: theme.colors.bg, borderRadius: 12, padding: 12, marginBottom: 10, borderWidth: 1, borderColor: theme.colors.border },
  rowNo: { fontSize: 13, fontWeight: '800', color: theme.colors.navy },
  cellLabel: { fontSize: 10, color: theme.colors.textMuted, fontWeight: '700', marginBottom: 3 },
  levelChip: { borderRadius: 16, paddingHorizontal: 12, paddingVertical: 8, minHeight: 36, alignItems: 'center', justifyContent: 'center' },
  levelChipIdle: { backgroundColor: '#fff', borderWidth: 1.5, borderColor: theme.colors.border },
  levelChipText: { fontSize: 12, fontWeight: '600' },
  totalsCard: { backgroundColor: theme.colors.primaryLight, borderRadius: 12, padding: 12, marginVertical: 12 },
  totalLabel: { fontSize: 13, color: theme.colors.navy, fontWeight: '700' },
  totalValue: { fontSize: 15, color: theme.colors.navy, fontWeight: '900' },
  hint: { fontSize: 11, color: theme.colors.textMuted, marginTop: 4 },
})
