import Constants from 'expo-constants'

const API_PORT = 8000

// The host this bundle was loaded from. In Expo Go the phone already reached this
// PC to download the JS, so the API on the same host is reachable — and this keeps
// working when DHCP hands the PC a different LAN IP.
function devHostFromExpo() {
  const hostUri =
    Constants.expoConfig?.hostUri ||
    Constants.expoGoConfig?.debuggerHost ||
    Constants.manifest2?.extra?.expoGo?.debuggerHost ||
    Constants.manifest?.debuggerHost
  if (!hostUri) return null
  const host = String(hostUri).split(':')[0]
  return host || null
}

// Resolution order:
//   1. EXPO_PUBLIC_API_BASE — explicit override. EAS preview/production builds pin
//      this to https://rted-api.onrender.com; an Android emulator can set it to
//      http://10.0.2.2:8000.
//   2. The Expo dev host (physical phone on the same Wi-Fi).
//   3. Last-resort LAN fallback if the host cannot be determined.
function resolveApiBase() {
  const explicit = process.env.EXPO_PUBLIC_API_BASE
  if (explicit) return explicit

  const host = devHostFromExpo()
  if (host) return `http://${host}:${API_PORT}`

  return `http://127.0.0.1:${API_PORT}`
}

export const API_BASE = resolveApiBase()
