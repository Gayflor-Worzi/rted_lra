import { useCallback, useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Badge, Btn } from '../ui'
import { targetMetricLabel } from '../lib/constants'

/**
 * A staff member's own performance targets, every one of them.
 *
 * The backend (`/dashboard/my` → `performance.targets`) returns ALL approved
 * targets for the signed-in user with the achieved value recomputed live from
 * the operational records and the percentage derived from those same numbers.
 * This component only formats that payload — it never does its own achievement
 * maths, so web and mobile can never disagree.
 *
 * Pass `performance` when the caller already fetched `/dashboard/my` (avoids a
 * second request); otherwise the component fetches it itself.
 */

const STATUS_TONE = { Approved: 'green', Pending: 'amber', Draft: 'slate', Archived: 'slate' }

const fmtTargetNum = (v) => {
  const n = Number(v)
  if (v === null || v === undefined || v === '' || Number.isNaN(n)) return '—'
  return n.toLocaleString('en-US', { maximumFractionDigits: 2 })
}

const fmtTargetDate = (d) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d || ''))
  if (!m) return ''
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
    .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const pctOf = (t) => {
  const n = Number(t?.completion_rate)
  return Number.isFinite(n) ? n : 0
}

// Overall progress is calculated server-side (weighted Σachieved / Σtarget) so
// web and mobile can never disagree; `null` means a zero combined target.
const fmtPct = (v) => {
  if (v === null || v === undefined || v === '') return 'N/A'
  const n = Number(v)
  if (!Number.isFinite(n)) return 'N/A'
  return String(Number(n.toFixed(1)))
}

export default function PerformanceTargets({ performance = null, className = '' }) {
  const [perf, setPerf] = useState(performance)
  const [loading, setLoading] = useState(!performance)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => { if (performance) setPerf(performance) }, [performance])

  const load = useCallback(async (fresh) => {
    try {
      // `fresh` bypasses the backend's short dashboard cache so the refresh
      // action re-reads the latest target/achievement values.
      const r = await api.get('/dashboard/my', fresh ? { params: { fresh: 1 } } : undefined)
      setPerf(unwrap(r).data?.performance || {})
      setErr(null)
    } catch (e) {
      setErr(errMsg(e, 'Could not load performance targets.'))
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  useEffect(() => {
    if (perf) { setLoading(false); return }
    load(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const targets = Array.isArray(perf?.targets) ? perf.targets : []
  // Weighted aggregate supplied by the same payload as `targets`; it is absent
  // only for older cached responses, in which case no summary is shown.
  const overall = perf?.overall || null
  const overallPct = fmtPct(overall?.completion_rate)
  const overallDisplay = overallPct === 'N/A' ? 'N/A' : `${overallPct}%`
  const overallWidth = Math.min(Math.max(Number(overall?.completion_rate) || 0, 0), 100)

  return (
    <Card
      title="My Performance Targets"
      className={`!bg-white !border-slate-200 ${className}`}
      headerClassName="!bg-white !border-slate-100"
      titleClassName="!text-slate-800"
      right={
        <Btn
          tone="white"
          onClick={() => { setRefreshing(true); load(true) }}
          disabled={refreshing || loading}
        >
          {refreshing ? 'Refreshing…' : 'Refresh'}
        </Btn>
      }
    >
      {err && <p className="text-sm text-red-600 mb-3">{err}</p>}

      {loading ? (
        <p className="text-sm text-slate-500">Loading your targets…</p>
      ) : targets.length === 0 ? (
        <p className="text-sm text-slate-500">No approved performance targets for your account yet.</p>
      ) : (
        <div className="space-y-4">
          {overall && (
            <div className="rounded-xl border border-brand-600 bg-brand-500 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-[11px] font-extrabold uppercase tracking-wide text-white">
                    Overall Performance
                  </div>
                  <div className="text-sm font-semibold text-white mt-1">
                    Achieved <b className="font-extrabold text-white">{fmtTargetNum(overall.achieved_value)}</b>
                    {' of '}
                    <b className="font-extrabold text-white">{fmtTargetNum(overall.target_value)}</b>
                    {' — '}
                    <span className="font-bold">{overallPct === 'N/A' ? 'N/A overall progress' : `${overallPct}% overall progress`}</span>
                  </div>
                </div>
                <div className="text-right shrink-0">
                  <div className="text-3xl font-extrabold text-white leading-none">{overallDisplay}</div>
                  <div className="text-[11px] font-semibold text-white mt-1">overall</div>
                </div>
              </div>

              <div className="mt-3 h-2.5 rounded-full bg-white/35 overflow-hidden">
                <div
                  className="h-full rounded-full bg-white"
                  style={{ width: `${overallWidth}%` }}
                />
              </div>

              <div className="grid grid-cols-3 gap-2 mt-3">
                <div className="rounded-lg border border-white/25 bg-[#151B54] px-2 py-2 text-center">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-white">Total achieved</div>
                  <div className="text-sm font-extrabold text-white">{fmtTargetNum(overall.achieved_value)}</div>
                </div>
                <div className="rounded-lg border border-white/25 bg-[#151B54] px-2 py-2 text-center">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-white">Combined target</div>
                  <div className="text-sm font-extrabold text-white">{fmtTargetNum(overall.target_value)}</div>
                </div>
                <div className="rounded-lg border border-white/25 bg-[#151B54] px-2 py-2 text-center">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-white">Targets included</div>
                  <div className="text-sm font-extrabold text-white">{fmtTargetNum(overall.targets_count)}</div>
                </div>
              </div>
            </div>
          )}

          {targets.map((t) => {
            const pct = pctOf(t)
            const start = fmtTargetDate(t.start_date) || fmtTargetDate(t.window?.start)
            const end = fmtTargetDate(t.end_date) || fmtTargetDate(t.window?.end)
            const period = start || end ? `${start || '—'} → ${end || '—'}` : ''
            const meta = [t.section, t.frequency, period].filter(Boolean).join(' · ')

            return (
              <div key={t.id} className="rounded-xl border border-white/25 bg-[#151B54] p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="font-bold text-white">
                      {targetMetricLabel(t.metric)}
                      {t.measurement_unit && <span className="text-[11px] font-semibold text-indigo-100"> ({t.measurement_unit})</span>}
                    </div>
                    <div className="text-xs font-semibold text-indigo-100 mt-0.5">{meta || '—'}</div>
                  </div>
                  <Badge tone={STATUS_TONE[t.status] || 'slate'}>{t.status}</Badge>
                </div>

                <div className="flex items-center justify-between mt-3 text-sm">
                  <span className="font-bold text-white">
                    Achieved <b className="font-extrabold text-white">{fmtTargetNum(t.achieved_value)}</b>
                    {' of '}
                    <b className="font-extrabold text-white">{fmtTargetNum(t.target_value)}</b>
                  </span>
                  <span className="font-extrabold text-white">{pct}%</span>
                </div>

                <div className="mt-2 h-2 rounded-full bg-white/35 overflow-hidden">
                  <div
                    className="h-full rounded-full bg-white"
                    style={{ width: `${Math.min(Math.max(pct, 0), 100)}%` }}
                  />
                </div>
              </div>
            )
          })}
        </div>
      )}
    </Card>
  )
}
