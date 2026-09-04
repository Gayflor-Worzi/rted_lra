import { useState } from 'react'
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, Keyboard } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { useAuth } from '../auth'
import { theme } from '../theme'
import { Btn, Logo } from '../components'

export default function LoginScreen() {
  const { login } = useAuth()
  const insets = useSafeAreaInsets()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)

  const go = async () => {
    Keyboard.dismiss()
    if (!email || !password) return Alert.alert('Missing', 'Enter email and password')
    setBusy(true)
    try {
      await login(email, password)
    } catch (e) {
      Alert.alert('Login failed', e.response?.data?.message || 'Network error — check your connection')
    } finally { setBusy(false) }
  }

  return (
    <KeyboardAvoidingView style={s.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[s.topBand, { paddingTop: insets.top }]} />
      <ScrollView contentContainerStyle={{ flexGrow: 1 }} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
        <View style={[s.body, { paddingTop: insets.top + 16 }]}>
          <View style={s.logoRow}>
            <Logo height={44} />
          </View>
          <Text style={s.title}>Field Operations</Text>
          <Text style={s.sub}>Property Tax · Enforcement · Compliance{'\n'}Sign in to continue</Text>

          <View style={s.form}>
            <Text style={s.label}>EMAIL ADDRESS</Text>
            <View style={s.inputWrap}>
              <Text style={s.icon}>✉️</Text>
              <TextInput style={s.input} placeholder="officer@lra.gov.lr" autoCapitalize="none"
                keyboardType="email-address" value={email} onChangeText={setEmail} placeholderTextColor={theme.colors.textLight} />
            </View>

            <Text style={[s.label, { marginTop: 14 }]}>PASSWORD</Text>
            <View style={s.inputWrap}>
              <Text style={s.icon}>🔒</Text>
              <TextInput style={s.input} placeholder="••••••••" secureTextEntry
                value={password} onChangeText={setPassword} placeholderTextColor={theme.colors.textLight} />
            </View>

            <TouchableOpacity style={[s.submit, busy && { opacity: 0.7 }]} onPress={go} disabled={busy}>
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={s.submitText}>SIGN IN</Text>}
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
  body: { flexGrow: 1, paddingHorizontal: 28, justifyContent: 'center' },
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
  footer: { alignItems: 'center', marginTop: 44, paddingBottom: 20 },
  footerText: { fontSize: 12, color: theme.colors.textLight },
  footerSub: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
})