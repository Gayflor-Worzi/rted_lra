import { useEffect, useRef, useState } from 'react'
import { View, Text, Modal, TouchableOpacity, StyleSheet } from 'react-native'
import { useAuth } from '../auth'
import { theme } from '../theme'

const IDLE_MS = 8 * 60 * 1000
const COUNTDOWN_MS = 10 * 1000

/**
 * Automatically signs the user out after 8 minutes of inactivity.
 * Once the idle threshold is reached a 10-second countdown overlay appears;
 * any touch (or the "Stay signed in" button) resets the timer.
 */
export default function IdleGate({ children }) {
  const { logout } = useAuth()
  const last = useRef(Date.now())
  const [left, setLeft] = useState(null)

  useEffect(() => {
    const iv = setInterval(() => {
      const idle = Date.now() - last.current
      if (idle >= IDLE_MS) {
        const remaining = IDLE_MS + COUNTDOWN_MS - idle
        if (remaining <= 0) {
          logout()
          return
        }
        setLeft(remaining)
      } else {
        setLeft(null)
      }
    }, 500)
    return () => clearInterval(iv)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const reset = () => {
    last.current = Date.now()
    setLeft(null)
  }

  const secs = left === null ? 0 : Math.ceil(left / 1000)

  return (
    <View style={s.root} onTouchStart={reset}>
      {children}
      <Modal transparent visible={left !== null} animationType="fade" onRequestClose={reset}>
        <View style={s.mask}>
          <View style={s.card}>
            <Text style={s.icon}>⏳</Text>
            <Text style={s.title}>Session timeout</Text>
            <Text style={s.body}>
              You have been inactive for 8 minutes. Logging out in {secs} {secs === 1 ? 'second' : 'seconds'}.
            </Text>
            <TouchableOpacity style={s.btn} onPress={reset}>
              <Text style={s.btnText}>Stay signed in</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>
    </View>
  )
}

const s = StyleSheet.create({
  root: { flex: 1 },
  mask: { flex: 1, backgroundColor: 'rgba(15,23,42,0.6)', alignItems: 'center', justifyContent: 'center', padding: 24 },
  card: { width: '100%', maxWidth: 360, backgroundColor: theme.colors.white, borderRadius: 20, paddingVertical: 32, paddingHorizontal: 20, alignItems: 'center' },
  icon: { fontSize: 36, marginBottom: 8 },
  title: { fontSize: 20, fontWeight: '900', color: theme.colors.navy },
  body: { fontSize: 14, color: theme.colors.textMuted, textAlign: 'center', lineHeight: 20, marginTop: 10 },
  btn: { marginTop: 20, width: '100%', backgroundColor: theme.colors.primary, borderRadius: 12, paddingVertical: 14, alignItems: 'center' },
  btnText: { color: '#fff', fontSize: 15, fontWeight: '800' },
})