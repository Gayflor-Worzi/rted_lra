import { useState } from 'react'
import { View, Text, TextInput, StyleSheet, Alert, ActivityIndicator, TouchableOpacity, KeyboardAvoidingView, Platform, ScrollView, Keyboard } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { useAuth } from '../auth'
import { theme } from '../theme'
import { Logo } from '../components'

export default function ResetPasswordScreen() {
  const { updateUser } = useAuth()
  const insets = useSafeAreaInsets()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)

  const go = async () => {
    Keyboard.dismiss()
    if (password.length < 8) return Alert.alert('Weak password', 'Use at least 8 characters.')
    if (password !== confirm) return Alert.alert('Mismatch', 'Passwords do not match.')
    setBusy(true)
    try {
      await api.post('/auth/reset-password', { password, password_confirmation: confirm })
      await updateUser({ must_reset_password: false })
    } catch (e) {
      Alert.alert('Update failed', e.response?.data?.message || 'Network error — try again.')
    } finally { setBusy(false) }
  }

  return (
    <KeyboardAvoidingView style={s.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[s.topBand, { paddingTop: insets.top }]} />
      <ScrollView contentContainerStyle={{ flexGrow: 1, paddingBottom: 20 }} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
        <View style={[s.body, { paddingTop: insets.top + 16 }]}>
          <View style={s.logoRow}><Logo height={44} /></View>
          <Text style={s.title}>Set a new password</Text>
          <Text style={s.sub}>First sign-in requires a password change before you can continue.</Text>

          <View style={s.form}>
            <Text style={s.label}>NEW PASSWORD</Text>
            <View style={s.inputWrap}>
              <Text style={s.icon}>🔒</Text>
              <TextInput style={s.input} placeholder="At least 8 characters" secureTextEntry
                autoCapitalize="none" autoComplete="off" value={password} onChangeText={setPassword}
                placeholderTextColor={theme.colors.textLight} />
            </View>

            <Text style={[s.label, { marginTop: 14 }]}>CONFIRM PASSWORD</Text>
            <View style={s.inputWrap}>
              <Text style={s.icon}>🔒</Text>
              <TextInput style={s.input} placeholder="Repeat the new password" secureTextEntry
                autoCapitalize="none" autoComplete="off" value={confirm} onChangeText={setConfirm}
                placeholderTextColor={theme.colors.textLight} />
            </View>

            <TouchableOpacity style={[s.submit, busy && { opacity: 0.7 }]} onPress={go} disabled={busy}>
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={s.submitText}>UPDATE PASSWORD</Text>}
            </TouchableOpacity>
          </View>

          <View style={s.footer}>
            <Text style={s.footerText}>Liberia Revenue Authority</Text>
            <Text style={s.footerSub}>Retd Field Collection System · v1.0</Text>
          </View>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

const s = StyleSheet.create({
  root: { flex: 1, backgroundColor: theme.colors.white },
  topBand: { backgroundColor: theme.colors.primary },
  body: { flexGrow: 1, paddingHorizontal: 28, justifyContent: 'center', paddingBottom: 20 },
  logoRow: { alignItems: 'center', marginBottom: 6 },
  title: { fontSize: 28, fontWeight: '900', color: theme.colors.navy, textAlign: 'center', marginTop: 8 },
  sub: { fontSize: 14, color: theme.colors.textMuted, textAlign: 'center', marginTop: 8, lineHeight: 20 },
  form: { marginTop: 32 },
  label: { fontSize: 12, fontWeight: '800', color: theme.colors.navy, letterSpacing: 1, marginBottom: 8, textTransform: 'uppercase' },
  inputWrap: { flexDirection: 'row', alignItems: 'center', backgroundColor: theme.colors.bg, borderRadius: 12, borderWidth: 1.5, borderColor: theme.colors.border, paddingHorizontal: 12 },
  icon: { fontSize: 15, marginRight: 10 },
  input: { flex: 1, paddingVertical: 13, fontSize: 15, color: theme.colors.text },
  submit: { backgroundColor: theme.colors.primary, borderRadius: 12, paddingVertical: 16, alignItems: 'center', marginTop: 26 },
  submitText: { color: '#fff', fontSize: 15, fontWeight: '800', letterSpacing: 1 },
  footer: { alignItems: 'center', marginTop: 44 },
  footerText: { fontSize: 12, color: theme.colors.textLight },
  footerSub: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
})