import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { errMsg, unwrap } from '../api'
import { useAuth } from '../auth'
import { Input, Btn, SuccessBox } from '../ui'
import { BRAND } from '../lib/brand'

export default function ForceReset() {
  const { refreshMe } = useAuth()
  const nav = useNavigate()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [err, setErr] = useState('')
  const [done, setDone] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      if (password.length < 8) { setErr('Password must be at least 8 characters.'); setBusy(false); return }
      if (password !== confirm) { setErr('Passwords do not match.'); setBusy(false); return }
      await api.post('/auth/reset-password', { password, password_confirmation: confirm })
      await refreshMe()
      setDone(true)
    } catch (ex) {
      setErr(errMsg(ex, 'Unable to update password.'))
    }
    setBusy(false)
  }

  if (done) {
    return (
      <div className="min-h-screen grid place-items-center bg-slate-50 px-4">
        <div className="w-full max-w-md bg-white rounded-2xl border border-slate-200 p-8 shadow-sm text-center">
          <div className="text-4xl mb-3">✅</div>
          <h1 className="text-xl font-bold text-navy-800">Password updated</h1>
          <p className="text-sm text-slate-500 mt-2 mb-6">Your session is secure. Continue to {BRAND.short}.</p>
          <Btn onClick={() => nav('/dashboard')} className="w-full">Continue to Dashboard</Btn>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen grid place-items-center bg-navy-800 px-4 relative overflow-hidden">
      <div className="absolute -top-24 right-0 w-80 h-80 rounded-full bg-brand-500/20 blur-3xl" />
      <div className="w-full max-w-md bg-white rounded-2xl border border-slate-200 p-8 shadow-xl relative">
        <div className="flex items-center gap-2 mb-1">
          <img src="/assets/lra-logo.png" alt="LRA" className="h-8 w-8 rounded object-contain bg-white" />
          <div className="font-bold text-navy-800">{BRAND.short}</div>
        </div>
        <h1 className="text-xl font-bold text-navy-800 mt-4">Set a new password</h1>
        <p className="text-sm text-slate-500 mt-1 mb-5">
          First sign-in requires a password change before you can continue.
        </p>
        {err && <div className="mb-4 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {err}</div>}
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1.5">New password</label>
            <Input type="password" className="w-full" autoComplete="new-password" value={password}
              onChange={(e) => setPassword(e.target.value)} required minLength={8} />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1.5">Confirm password</label>
            <Input type="password" className="w-full" autoComplete="new-password" value={confirm}
              onChange={(e) => setConfirm(e.target.value)} required minLength={8} />
          </div>
          <Btn type="submit" disabled={busy} className="w-full py-3">{busy ? 'Updating…' : 'Update Password'}</Btn>
        </form>
      </div>
    </div>
  )
}