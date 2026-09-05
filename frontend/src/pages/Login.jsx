import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { errMsg } from '../api'
import { BRAND } from '../lib/brand'
import { Input, Btn, SuccessBox } from '../ui'

export default function Login() {
  const { login } = useAuth()
  const nav = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [err, setErr] = useState('')
  const [info, setInfo] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setInfo(''); setBusy(true)
    try {
      const r = await login(email, password)
      if (r.mustReset) {
        setEmail(''); setPassword('')
        setInfo('First sign-in: create a new password to continue.')
        nav('/force-reset')
      } else if (r.ok) {
        nav('/dashboard')
      } else {
        setErr(r.message)
      }
    } catch (ex) {
      setErr(errMsg(ex, 'Invalid credentials.'))
    }
    setBusy(false)
  }

  return (
    <div className="min-h-screen grid lg:grid-cols-2">
      {/* Brand panel */}
      <div className="hidden lg:flex flex-col justify-between bg-navy-800 p-12 relative overflow-hidden">
        <div className="absolute -top-32 -right-32 w-96 h-96 rounded-full bg-brand-500/20 blur-3xl" />
        <div className="absolute -bottom-40 -left-24 w-96 h-96 rounded-full bg-navy-500/40 blur-3xl" />
        <div className="relative">
          <div className="flex items-center gap-3">
            <div className="w-14 h-14 rounded-lg bg-white/95 grid place-items-center p-1 overflow-hidden">
              <img src="/assets/lra-logo.png" alt="LRA" className="max-w-full max-h-full object-contain" />
            </div>
            <div>
              <div className="font-bold text-white text-xl">{BRAND.full}</div>
              <div className="text-xs text-slate-300">{BRAND.org}</div>
            </div>
          </div>
        </div>
        <div className="relative space-y-3">
          <div className="w-1 h-10 bg-brand-500 rounded-full mb-2" />
          <h1 className="text-3xl font-bold text-white leading-snug">{BRAND.dept}</h1>
          <p className="text-slate-300 max-w-md leading-relaxed">{BRAND.tagline}</p>
          <p className="text-[11px] text-slate-400 border-l-2 border-navy-500 pl-3">{BRAND.notice}</p>
        </div>
        <div className="relative text-xs text-slate-400">
          © {new Date().getFullYear()} {BRAND.org}. All rights reserved.
        </div>
      </div>

      {/* Login form */}
      <div className="flex items-center justify-center p-8 bg-slate-50">
        <form onSubmit={submit} className="w-full max-w-sm">
          <div className="lg:hidden flex justify-center mb-6">
            <div className="w-32 flex items-center justify-center p-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
              <img src="/assets/lra-logo.png" alt="LRA" className="max-w-full object-contain" />
            </div>
          </div>
          <h2 className="text-2xl font-bold text-navy-800">Sign in</h2>
          <p className="text-sm text-slate-500 mb-6">RETD internal task & case console</p>
          {info && <div className="mb-4"><SuccessBox>{info}</SuccessBox></div>}
          {err && <div className="mb-4 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {err}</div>}
          <label className="block text-xs font-semibold text-slate-600 mb-1.5">Email</label>
          <Input className="w-full mb-4" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="username" required placeholder="you@lra.gov.lr" />
          <label className="block text-xs font-semibold text-slate-600 mb-1.5">Password</label>
          <Input className="w-full mb-6" value={password} onChange={(e) => setPassword(e.target.value)} type="password" autoComplete="current-password" required placeholder="••••••••" />
          <Btn type="submit" disabled={busy} className="w-full py-3 shadow-lg shadow-brand-500/20">
            {busy ? 'Signing in…' : 'Sign In'}
          </Btn>
          <p className="text-center text-xs text-slate-400 mt-6">Internal use · LRA — RETD</p>
          <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-4">
            <div className="flex items-center gap-3">
              <div className="w-11 h-11 rounded-xl bg-brand-50 grid place-items-center text-xl shrink-0">📲</div>
              <div className="min-w-0">
                <div className="text-sm font-bold text-navy-800">Field App</div>
                <div className="text-[11px] text-slate-500">Android companion for field officers · works offline</div>
              </div>
            </div>
            <a href={BRAND.apkUrl}
              className="mt-3 flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition">
              ⬇ Download APK
            </a>
          </div>
        </form>
      </div>
    </div>
  )
}