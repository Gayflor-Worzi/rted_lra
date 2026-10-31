import { useCallback, useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Btn } from '../ui'
import { targetMetricLabel } from '../lib/constants'

const number = (value) => Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })

export default function ManagementPerformance({ className = '' }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)

  const load = useCallback((refresh = false) => {
    if (refresh) setRefreshing(true)
    else setLoading(true)
    setError('')
    api.get('/dashboard/performance/management')
      .then((response) => setData(unwrap(response).data))
      .catch((cause) => setError(errMsg(cause, 'Could not load management performance.')))
      .finally(() => { setLoading(false); setRefreshing(false) })
  }, [])

  useEffect(() => { load() }, [load])

  return (
    <Card
      title="Aggregated Management Performance"
      className={className}
      right={<Btn tone="white" onClick={() => load(true)} disabled={loading || refreshing}>{refreshing ? 'Refreshing…' : 'Refresh'}</Btn>}
    >
      {error && <p className="mb-3 text-sm text-red-600">{error}</p>}
      {error ? null : loading ? (
        <p className="text-sm text-slate-500">Loading management performance…</p>
      ) : !data ? (
        <p className="text-sm text-slate-500">Management performance is unavailable.</p>
      ) : (
        <div className="space-y-3">
          <p className="text-xs font-semibold text-slate-500">{data.scope_label} · Approved target windows</p>
          {data.status !== 'available' && (
            <p className="text-sm text-slate-500">No attributable supported operational targets are available for this management scope.</p>
          )}
          {data.attribution_limited && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
              Historical attribution is limited for {data.unattributed_targets_count} target window(s); figures include only periods with recorded organizational assignments.
            </p>
          )}
          {data.periods.map((period) => (
            <div key={`${period.metric}|${period.measurement_unit}|${period.start_date}|${period.end_date}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="font-bold text-slate-800">
                    {targetMetricLabel(period.metric)}{period.measurement_unit ? ` (${period.measurement_unit})` : ''}
                  </h3>
                  <p className="text-xs text-slate-500">{period.start_date} – {period.end_date}</p>
                </div>
                <strong className="text-lg text-navy-800">{period.completion_rate == null ? 'N/A' : `${period.completion_rate}%`}</strong>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                <div><span className="block text-xs text-slate-500">Eligible achievement</span><strong>{number(period.achieved_value)}</strong></div>
                <div><span className="block text-xs text-slate-500">Eligible target</span><strong>{number(period.target_value)}</strong></div>
                <div><span className="block text-xs text-slate-500">Operational staff</span><strong>{number(period.contributing_staff_count)}</strong></div>
                <div><span className="block text-xs text-slate-500">Approved targets</span><strong>{number(period.targets_count)}</strong></div>
              </div>
              {period.status === 'no_achievements' && <p className="mt-2 text-xs text-amber-700">No eligible achievements were recorded for this target window.</p>}
              {period.attribution_limited && <p className="mt-2 text-xs text-amber-700">Part of this target window predates recorded organizational history.</p>}
            </div>
          ))}
        </div>
      )}
      {!loading && !error && data?.unsupported_metrics?.length > 0 && (
        <p className="mt-3 text-xs text-slate-500">
          Not calculated because no live target rule exists: {data.unsupported_metrics.map(targetMetricLabel).join(', ')}.
        </p>
      )}
    </Card>
  )
}
