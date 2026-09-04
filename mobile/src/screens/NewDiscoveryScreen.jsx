import { useState } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, Image } from 'react-native'
import * as Location from 'expo-location'
import * as ImagePicker from 'expo-image-picker'
import api from '../api'
import { useSync } from '../sync'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { useAuth } from '../auth'
import { theme } from '../theme'
import { Screen, Field, Btn, Input, Card, ChipRow, Empty } from '../components'

const PROPERTY_CLASSIFICATIONS = [
  'Residential',
  'Commercial',
  'Industrial',
  'Unimproved Land',
  'Residential Building on public land',
  'Commercial Building on public land',
  'Developed Land Residential',
  'Vacant land',
  'Mixed Use',
]

const PROPERTY_TYPES = ['New Property', 'Existing Property']

const A = (set) => (v) => set(v ?? '')

export default function NewDiscoveryScreen({ navigation }) {
  const { queueAndFlush } = useSync()
  const { user } = useAuth()

  const [ownerName, setOwnerName] = useState('')
  const [ownerContact, setOwnerContact] = useState('')
  const [tin, setTin] = useState('')
  const [address, setAddress] = useState('')
  const [county, setCounty] = useState('')
  const [district, setDistrict] = useState('')
  const [cityTown, setCityTown] = useState('')
  const [community, setCommunity] = useState('')
  const [street, setStreet] = useState('')
  const [houseNumber, setHouseNumber] = useState('')
  const [classification, setClassification] = useState('')
  const [propertyType, setPropertyType] = useState('')
  const [occupancyUse, setOccupancyUse] = useState('')
  const [description, setDescription] = useState('')
  const [gpsLat, setGpsLat] = useState('')
  const [gpsLng, setGpsLng] = useState('')
  const [gpsAccuracy, setGpsAccuracy] = useState('')
  const [discoveryDate, setDiscoveryDate] = useState(new Date().toISOString().slice(0, 10))
  const [photos, setPhotos] = useState([])
  const [proof, setProof] = useState(null) // proof of ownership (optional)
  const [locating, setLocating] = useState(false)
  const [busy, setBusy] = useState(false)

  const useMyLocation = async () => {
    setLocating(true)
    try {
      const { status, canAskAgain } = await Location.requestForegroundPermissionsAsync()
      if (status !== 'granted') {
        Alert.alert(
          'Location access needed',
          canAskAgain
            ? 'Grant location permission to stamp the discovery with GPS.'
            : 'Location permission was denied. Enable it in your device settings, then try again.'
        )
        return
      }
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
      setGpsLat(loc.coords.latitude.toFixed(6))
      setGpsLng(loc.coords.longitude.toFixed(6))
      setGpsAccuracy(loc.coords.accuracy != null ? String(Math.round(loc.coords.accuracy)) : '')
    } catch {
      Alert.alert('GPS error', 'Could not get a location fix. Move to open sky and try again.')
    } finally { setLocating(false) }
  }

  const requireCamera = async () => {
    const { status, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (status !== 'granted') {
      Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission to capture field photographs.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
      return false
    }
    return true
  }

  const takePhoto = async () => {
    if (!(await requireCamera())) return
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7 })
    if (!result.canceled) setPhotos((p) => [...p, result.assets[0].uri])
  }

  const uploadPhoto = async () => {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (status !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach photos.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', quality: 0.7, allowsMultipleSelection: true })
    if (!result.canceled) setPhotos((p) => [...p, ...result.assets.map((a) => a.uri)])
  }

  const removePhoto = (i) => setPhotos((p) => p.filter((_, x) => x !== i))

  const takeProof = async () => {
    if (!(await requireCamera())) return
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7 })
    if (!result.canceled) setProof(result.assets[0].uri)
  }

  const uploadProof = async () => {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (status !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach a file.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', quality: 0.7 })
    if (!result.canceled) setProof(result.assets[0].uri)
  }

  const submit = async () => {
    if (!address && !gpsLat && !gpsLng) return Alert.alert('Required', 'Enter a property address or GPS coordinates.')
    setBusy(true)
    const payload = {
      owner_name: ownerName.trim() || undefined,
      owner_contact: ownerContact.trim() || undefined,
      tin: tin.trim() || undefined,
      property_address: address.trim() || undefined,
      county: county.trim() || undefined,
      district: district.trim() || undefined,
      city_town: cityTown.trim() || undefined,
      community: community.trim() || undefined,
      street: street.trim() || undefined,
      house_number: houseNumber.trim() || undefined,
      property_classification: classification.trim() || undefined,
      property_type: propertyType.trim() || undefined,
      occupancy_use: occupancyUse.trim() || undefined,
      description: description.trim() || undefined,
      gps_lat: gpsLat ? parseFloat(gpsLat) : undefined,
      gps_lng: gpsLng ? parseFloat(gpsLng) : undefined,
      gps_coordinate: gpsLat && gpsLng ? `${gpsLat},${gpsLng}` : undefined,
      gps_accuracy: gpsAccuracy ? Math.round(Number(gpsAccuracy)) : undefined,
      gps_captured_at: gpsLat && gpsLng ? new Date().toISOString() : undefined,
      discovery_date: discoveryDate || undefined,
    }
    try {
      const r = await api.post('/discoveries', payload)
      const d = r.data?.data || {}
      const id = d.id
      let attached = 0
      for (const uri of photos) {
        try {
          await api.post('/evidence/photos', {
            photo_type: 'PROPERTY_FULL_VIEW',
            discovery_id: id,
            path: uri,
            gps_lat: payload.gps_lat,
            gps_lng: payload.gps_lng,
          })
          attached++
        } catch { /* a photo that fails is not fatal — it can be re-added later */ }
      }
      if (proof) {
        try {
          await api.post('/evidence/photos', {
            photo_type: 'OTHER',
            discovery_id: id,
            path: proof,
            gps_lat: payload.gps_lat,
            gps_lng: payload.gps_lng,
            remarks: 'Proof of ownership (optional)',
          })
        } catch { /* optional proof of ownership; non-fatal */ }
      }
      Alert.alert('Discovery recorded', `${d.discovery_reference || 'Record'} registered — ${attached} of ${photos.length} photo(s) attached.`, [
        { text: 'OK', onPress: () => navigation.goBack() },
      ])
    } catch (ex) {
      if (isOnlineRejection(ex)) {
        Alert.alert('Not recorded', serverMessage(ex, 'The server rejected the discovery.'))
      } else {
        await queueAndFlush('discovery', payload)
        Alert.alert('Saved offline', 'Discovery queued — it will sync when you are online.', [{ text: 'OK', onPress: () => navigation.goBack() }])
      }
    } finally { setBusy(false) }
  }

  if (!can(user, 'discovery.create')) {
    return (
      <Screen>
        <Empty icon="??" title="No access" sub="Your role does not include permission to record property discoveries." />
      </Screen>
    )
  }

  return (
    <Screen>
      <Card style={{ marginTop: 14 }}>
        <Text style={s.cardHeading}>Owner / Occupant</Text>
        <Field label="Owner / occupant name">
          <Input value={ownerName} onChangeText={A(setOwnerName)} placeholder="If known" />
        </Field>
        <Field label="Owner contact">
          <Input value={ownerContact} onChangeText={A(setOwnerContact)} placeholder="Phone / email" keyboardType="phone-pad" />
        </Field>
        <Field label="TIN">
          <Input value={tin} onChangeText={A(setTin)} placeholder="Taxpayer ID number" />
        </Field>
      </Card>

      <Card>
        <Text style={s.cardHeading}>Location</Text>
        <Field label="Property address">
          <Input value={address} onChangeText={A(setAddress)} placeholder="Street, city/town, community" />
        </Field>
        <Field label="County">
          <Input value={county} onChangeText={A(setCounty)} placeholder="e.g. Montserrado" />
        </Field>
        <Field label="District">
          <Input value={district} onChangeText={A(setDistrict)} placeholder="e.g. Greater Monrovia" />
        </Field>
        <Field label="City / town">
          <Input value={cityTown} onChangeText={A(setCityTown)} placeholder="e.g. Monrovia" />
        </Field>
        <Field label="Community">
          <Input value={community} onChangeText={A(setCommunity)} placeholder="e.g. Sinkor" />
        </Field>
        <Field label="Street">
          <Input value={street} onChangeText={A(setStreet)} placeholder="Street / road" />
        </Field>
        <Field label="House number">
          <Input value={houseNumber} onChangeText={A(setHouseNumber)} placeholder="House / building number" />
        </Field>
      </Card>

      <Card>
        <Text style={s.cardHeading}>Classification</Text>
        <Field label="Property classification">
          <ChipRow options={PROPERTY_CLASSIFICATIONS} value={classification} onChange={setClassification} />
        </Field>
        <Field label="Property type">
          <ChipRow options={PROPERTY_TYPES} value={propertyType} onChange={setPropertyType} />
        </Field>
        <Field label="Occupancy / use">
          <Input value={occupancyUse} onChangeText={A(setOccupancyUse)} placeholder="e.g. Owner-occupied rental" />
        </Field>
        <Field label="Description">
          <Input
            value={description}
            onChangeText={A(setDescription)}
            multiline
            style={{ minHeight: 80, textAlignVertical: 'top' }}
            placeholder="Structure, surroundings, notable features"
          />
        </Field>
      </Card>

      <Card>
        <Text style={s.cardHeading}>GPS Location</Text>
        <Field label="GPS latitude">
          <Input value={gpsLat} onChangeText={A(setGpsLat)} keyboardType="decimal-pad" placeholder="e.g. 6.315611" />
        </Field>
        <Field label="GPS longitude">
          <Input value={gpsLng} onChangeText={A(setGpsLng)} keyboardType="decimal-pad" placeholder="e.g. -10.807407" />
        </Field>
        <Field label="GPS accuracy (m)">
          <Input value={gpsAccuracy} onChangeText={A(setGpsAccuracy)} keyboardType="numeric" placeholder="e.g. 12" />
        </Field>
        <Btn tone="navy" label={locating ? 'Locating…' : '📡 Use my location'} onPress={useMyLocation} loading={locating} />
      </Card>

      <Card>
        <Text style={s.cardHeading}>Discovery details</Text>
        <Field label="Discovery date">
          <Input value={discoveryDate} onChangeText={A(setDiscoveryDate)} placeholder="mm/dd/yyyy" />
        </Field>

        <Text style={s.photoLabel}>Field photographs ({photos.length} captured)</Text>
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
          <TouchableOpacity style={s.captureBtn} onPress={takePhoto}><Text style={s.captureText}>📷 Take photo</Text></TouchableOpacity>
          <TouchableOpacity style={s.captureBtnAlt} onPress={uploadPhoto}><Text style={s.captureTextAlt}>⬆ Upload</Text></TouchableOpacity>
        </View>
        {photos.length > 0 && (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 }}>
            {photos.map((uri, i) => (
              <TouchableOpacity key={i} onPress={() => removePhoto(i)}>
                <Image source={{ uri }} style={{ width: 84, height: 84, borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border }} />
              </TouchableOpacity>
            ))}
          </View>
        )}

        <Text style={s.photoLabel}>Proof of ownership <Text style={{ color: theme.colors.textLight }}>(optional)</Text></Text>
        {proof ? (
          <TouchableOpacity onPress={() => setProof(null)} style={{ marginTop: 8 }}>
            <Image source={{ uri: proof }} style={{ width: 112, height: 84, borderRadius: 10, borderWidth: 1.5, borderColor: theme.colors.success }} />
          </TouchableOpacity>
        ) : (
          <View style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
            <TouchableOpacity style={s.captureBtn} onPress={takeProof}><Text style={s.captureText}>📷 Take photo</Text></TouchableOpacity>
            <TouchableOpacity style={s.captureBtnAlt} onPress={uploadProof}><Text style={s.captureTextAlt}>⬆ Upload</Text></TouchableOpacity>
          </View>
        )}
      </Card>

      <View style={{ paddingHorizontal: 16, marginTop: 6 }}>
        <Btn label="Record discovery" onPress={submit} loading={busy} disabled={busy} />
      </View>
    </Screen>
  )
}

const s = StyleSheet.create({
  cardHeading: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 },
  photoLabel: { fontSize: 12, color: theme.colors.textMuted, marginTop: 14, fontWeight: '600' },
  captureBtn: { backgroundColor: theme.colors.navy, borderRadius: 8, paddingHorizontal: 14, paddingVertical: 12, minHeight: 44, justifyContent: 'center' },
  captureText: { color: '#fff', fontWeight: '700', fontSize: 13 },
  captureBtnAlt: { backgroundColor: '#fff', borderRadius: 8, paddingHorizontal: 14, paddingVertical: 12, minHeight: 44, justifyContent: 'center', borderWidth: 1.5, borderColor: theme.colors.border },
  captureTextAlt: { color: theme.colors.navy, fontWeight: '700', fontSize: 13 },
})
