export function Card({ title, right, children, className = '', headerClassName = '', titleClassName = '' }) {
  return (
    <div className={`bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden ${className}`}>
      {(title || right) && (
        <div className={`flex items-center justify-between px-5 py-3.5 border-b border-slate-100 bg-white ${headerClassName}`}>
          <h2 className={`font-semibold text-slate-800 flex items-center gap-2 ${titleClassName}`}>
            <span className="w-1 h-4 rounded bg-brand-500 inline-block" />
            {title}
          </h2>
          {right}
        </div>
      )}
      <div className="p-5">{children}</div>
    </div>
  )
}

export function Stat({ label, value, tone = 'slate', icon, badge }) {
  const tones = {
    slate: 'text-navy-800', blue: 'text-brand-500', green: 'text-emerald-600',
    red: 'text-red-600', amber: 'text-amber-600', navy: 'text-navy-500',
  }
  return (
    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 flex items-start justify-between">
      <div className="min-w-0">
        <div className="text-xs uppercase tracking-wider text-slate-400">{label}</div>
        <div className={`text-2xl font-bold mt-1 ${tones[tone]}`}>{value}</div>
        {badge && <span className="mt-1.5 inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-slate-100 text-slate-500">{badge}</span>}
      </div>
      {icon && <div className="text-2xl opacity-80">{icon}</div>}
    </div>
  )
}

export function Badge({ children, tone = 'slate' }) {
  const map = {
    slate: 'bg-slate-100 text-slate-600',
    green: 'bg-emerald-100 text-emerald-700',
    red: 'bg-red-100 text-red-700',
    amber: 'bg-amber-100 text-amber-700',
    blue: 'bg-blue-100 text-blue-700',
    brand: 'bg-brand-50 text-brand-700',
    navy: 'bg-navy-50 text-navy-800',
  }
  return <span className={`inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold ${map[tone] || map.slate}`}>{children}</span>
}

export const statusTone = (s) => ({
  Approved: 'green', Paid: 'green', Registered: 'green', Completed: 'green',
  Rejected: 'red', Overdue: 'red',
  Submitted: 'amber', Answered: 'blue',
  Billed: 'navy', 'Tax Cleared': 'green', Closed: 'slate',
  Enforcement: 'amber', New: 'slate',
}[s] || 'slate')

export const taskTone = (s) => ({
  Paid: 'green', Resolved: 'green', Closed: 'slate', Delivered: 'blue',
  'Out for Delivery': 'blue', Assigned: 'blue', Logged: 'slate',
  Escalated: 'red', 'Payment Rejected': 'red',
  '30-Day Warning': 'amber', '72-Hour Warning': 'amber', 'Verification Pending': 'amber',
  'Awaiting Assignment': 'navy', 'Payment Follow-up': 'brand', Outstanding: 'amber',
}[s] || 'slate')

export const billCaseTone = (s) => ({
  Paid: 'green', Resolved: 'green', Closed: 'slate', Delivered: 'blue',
  'Out for Delivery': 'blue', Assigned: 'blue',
  Escalated: 'red', '30-Day Warning': 'amber', '72-Hour Warning': 'amber',
  'Awaiting Assignment': 'navy', 'Under Verification': 'brand',
}[s] || 'slate')

export const fmtMoney = (n) => 'US$ ' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * Property-value formatter. Unlike fmtMoney it never invents a zero: a missing
 * assessment must read as "Not yet assessed" so an unassessed property is not
 * presented as though it had been valued at US$0.00. A stored 0 is still shown
 * as US$0.00, because a deliberate zero assessment and no assessment are
 * different facts.
 */
export const fmtValue = (n, absent = 'Not yet assessed') => {
  if (n === null || n === undefined || n === '') return absent
  const num = Number(n)
  if (Number.isNaN(num)) return String(n)
  return 'US$ ' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
/**
 * Editable-number formatter. Money and rate columns are stored as fixed-scale
 * decimals, so the API hands back "1.5000" and "0.0250" for values an officer
 * entered as 1.5 and 0.025. Trailing zeros are padding, not information, so they
 * are trimmed for display and for number inputs — otherwise re-opening a saved
 * form silently rewrites what was typed. Only trailing zeros are touched: the
 * digits, the decimal point, and any leading minus survive, and an empty field
 * stays empty rather than becoming "0".
 */
export const fmtNum = (n) => {
  if (n === null || n === undefined || n === '') return ''
  const s = String(n)
  if (!s.includes('.')) return s
  return s.replace(/0+$/, '').replace(/\.$/, '')
}

export const fmtDate = (d) => (d ? String(d).slice(0, 10) : '—')
export const fmtTime = (d) => (d ? String(d).replace('T', ' ').slice(0, 16) : '—')

/**
 * A staff member's role name, whichever shape the endpoint used.
 *
 * The staff lookups return `role` as a plain string, while some payloads carry a
 * nested role object. Reading `user.role.name` against a string yields
 * undefined, which silently emptied the assignment dropdowns — every officer
 * filtered out and the picker looked unpopulated. This tolerates both.
 */
export const roleName = (user) => {
  const r = user?.role
  if (!r) return ''
  return typeof r === 'string' ? r : (r.name || '')
}


export const Spinner = ({ label = 'Loading…' }) => {
  return (
    <div className="flex flex-col items-center justify-center py-16 gap-3">
      <div className="w-9 h-9 border-[3px] border-slate-200 border-t-brand-500 rounded-full animate-spin" />
      <div className="text-sm text-slate-400">{label}</div>
    </div>
  )
}

export function ErrorBox({ error }) {
  if (!error) return null
  const msg = error?.response?.data?.message || error.message
  return (
    <div className="mb-3 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm flex items-start gap-2">
      <span>⚠️</span><span>{msg}</span>
    </div>
  )
}

export function SuccessBox({ children }) {
  if (!children) return null
  return <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm mb-3">{children}</div>
}

export function PageTitle({ children, sub, right }) {
  return (
    <div className="flex items-center justify-between mb-5">
      <div>
        <h1 className="text-2xl font-bold text-navy-800">{children}</h1>
        {sub && <p className="text-sm text-slate-500 mt-1">{sub}</p>}
      </div>
      {right}
    </div>
  )
}

export function Btn({ children, onClick, tone = 'primary', disabled, className = '', type }) {
  const tones = {
    primary: 'bg-brand-500 hover:bg-brand-600 text-white',
    navy: 'bg-navy-500 hover:bg-navy-600 text-white',
    success: 'bg-emerald-600 hover:bg-emerald-700 text-white',
    danger: 'bg-red-600 hover:bg-red-700 text-white',
    amber: 'bg-amber-500 hover:bg-amber-600 text-white',
    white: 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-200',
  }
  return (
    <button type={type} onClick={onClick} disabled={disabled}
      className={`px-4 py-2 rounded-xl text-sm font-semibold transition disabled:opacity-50 ${tones[tone] || tones.primary} ${className}`}>
      {children}
    </button>
  )
}

export function Input({ className = '', ...props }) {
  return <input {...props} className={`px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 ${className}`} />
}