// Lightweight SVG chart primitives (no chart library) — donut, horizontal bars,
// grouped vertical bars. All use the brand palette from index.css.

export const CHART_COLORS = [
  '#C70000', '#002060', '#0EA5E9', '#10B981', '#F59E0B',
  '#8B5CF6', '#EC4899', '#14B8A6', '#F43F5E', '#64748B',
]

const fmtN = (v) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : `${v}`)

function colorAt(i, data) {
  return (data[i] && data[i].color) || CHART_COLORS[i % CHART_COLORS.length]
}

/* ---------- Donut / pie ---------- */

const polar = (cx, cy, r, deg) => ({
  x: cx + r * Math.cos(((deg - 90) * Math.PI) / 180),
  y: cy + r * Math.sin(((deg - 90) * Math.PI) / 180),
})

function segment(cx, cy, r1, r2, start, end) {
  const large = end - start <= 180 ? 0 : 1
  const p1 = polar(cx, cy, r1, end)
  const p2 = polar(cx, cy, r2, end)
  const p3 = polar(cx, cy, r2, start)
  const p4 = polar(cx, cy, r1, start)
  return `M ${p1.x} ${p1.y} A ${r1} ${r1} 0 ${large} 1 ${p4.x} ${p4.y} L ${p3.x} ${p3.y} A ${r2} ${r2} 0 ${large} 0 ${p2.x} ${p2.y} Z`
}

/** data: [{ label, value, color?, id? }]; size = canvas px; thickness = ring px. onClick(d) fires when
 *  a slice or its legend entry is clicked (d = the item with label/value/id). */
export function Donut({ data, size = 190, thickness = 30, center, onClick }) {
  const items = (data || []).filter((d) => d && d.value > 0)
  const total = items.reduce((s, d) => s + d.value, 0)
  if (total <= 0) return <p className="text-sm text-slate-400">No data to chart.</p>

  const cx = size / 2
  const cy = size / 2
  const r1 = size / 2 - thickness
  const r2 = size / 2 - thickness / 2
  let angle = 0

  return (
    <div className="flex flex-col sm:flex-row items-center gap-4">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
          {items.map((d, i) => {
            const slice = (d.value / total) * 360
            const path = segment(cx, cy, r1, r2, angle, angle + slice)
            angle += slice
            return (
              <path
                key={i}
                d={path}
                fill={colorAt(i, items)}
                stroke="#fff"
                strokeWidth="1.5"
                tabIndex={onClick ? 0 : undefined}
                role={onClick ? 'button' : undefined}
                aria-label={`${d.label}: ${d.value}`}
                onClick={onClick ? () => onClick(d) : undefined}
                style={onClick ? { cursor: 'pointer' } : undefined}
              >
                <title>{`${d.label}: ${d.value}`}</title>
              </path>
            )
          })}
        </svg>
        <div className="absolute inset-0 grid place-items-center">
          <div className="text-center">
            <div className="text-2xl font-extrabold text-navy-800">{fmtN(total)}</div>
            <div className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">{center || 'total'}</div>
          </div>
        </div>
      </div>
      <ul className="w-full space-y-1.5 min-w-0">
        {items.map((d, i) => (
          <li
            key={i}
            onClick={onClick ? () => onClick(d) : undefined}
            className={`flex items-center justify-between gap-2 text-sm ${onClick ? 'cursor-pointer hover:bg-slate-50 rounded-lg px-1.5 -mx-1.5 py-0.5' : ''}`}>
            <span className="flex items-center gap-2 text-slate-600 min-w-0">
              <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: colorAt(i, items) }} />
              <span className="truncate">{d.label}</span>
            </span>
            <span className="font-bold text-navy-800 shrink-0">{fmtN(d.value)}<span className="text-[10px] text-slate-400 font-medium"> · {Math.round((d.value / total) * 100)}%</span></span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/* ---------- Horizontal ranked bars ---------- */

/** data: [{ label, value, color?, meta? }] — sorted descending by value. */
export function HBars({ data, format = fmtN }) {
  const items = [...(data || [])].sort((a, b) => b.value - a.value).filter((d) => d.value > 0)
  if (items.length === 0) return <p className="text-sm text-slate-400">No data in range.</p>
  const max = Math.max(...items.map((d) => d.value), 1)

  return (
    <ul className="space-y-2.5">
      {items.map((d, i) => (
        <li key={i}>
          <div className="flex items-center justify-between gap-3 mb-1">
            <span className="text-sm text-slate-600 truncate">{d.label}</span>
            <span className="text-sm font-bold text-navy-800 shrink-0">{format(d.value)}</span>
          </div>
          <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden">
            <div className="h-full rounded-full transition-all" style={{ width: `${Math.max((d.value / max) * 100, 3)}%`, background: colorAt(i, items) }} />
          </div>
          {d.meta && <div className="text-[11px] text-slate-400 mt-0.5">{d.meta}</div>}
        </li>
      ))}
    </ul>
  )
}

/* ---------- Grouped vertical bars ---------- */

/**
 * VBars — comparison of several series across a set of groups.
 *  data: { label, [seriesKey]: value, id? }[]
 *  series: [{ key, label, color? }]
 *  onClick({ label, value, key, id }) fires when a bar is clicked.
 */
export function VBars({ data, series, height = 190, format = fmtN, onClick, showPct }) {
  const hasData = (g) => series.some((s) => (g[s.key] ?? 0) > 0)
  const groups = (data || []).filter(hasData)
  if (groups.length === 0) return <p className="text-sm text-slate-400">No data to chart.</p>

  // Single-series charts (e.g. count by staff / task status) read best as a
  // ranked list with value and share-of-total, where the whole row is clickable
  // to drill into the detail table. Multi-series comparisons keep the grouped
  // vertical bars below; any chart with many categories also falls into the list
  // so it never degenerates into overlapping bars.
  const asList = series.length === 1 || groups.length > 8
  if (asList) {
    const items = groups
      .map((g) => ({ label: g.label, value: series.map((s) => g[s.key] ?? 0).reduce((a, b) => a + b, 0), id: g.id }))
      .sort((a, b) => b.value - a.value)
    const total = items.reduce((s, d) => s + d.value, 0)
    const max = Math.max(...items.map((d) => d.value), 1)
    return (
      <ul className="space-y-2.5">
        {items.map((d, i) => (
          <li
            key={d.id ?? i}
            onClick={onClick ? () => onClick({ label: d.label, value: d.value, key: series[0]?.key, id: d.id }) : undefined}
            className={onClick ? 'cursor-pointer group' : undefined}
            role={onClick ? 'button' : undefined}
          >
            <div className={`flex items-center justify-between gap-3 mb-1 ${onClick ? 'group-hover:text-brand-600' : ''}`}>
              <span className="text-sm text-slate-600 truncate">{d.label}</span>
              <span className="text-sm font-bold text-navy-800 shrink-0">
                {format(d.value)}
                {showPct !== false && <span className="text-[10px] text-slate-400 font-medium"> · {Math.round((d.value / total) * 100)}%</span>}
              </span>
            </div>
            <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all ${onClick ? 'group-hover:opacity-80' : ''}`}
                style={{ width: `${Math.max((d.value / max) * 100, 3)}%`, background: series[0]?.color || CHART_COLORS[0] }}
              />
            </div>
          </li>
        ))}
      </ul>
    )
  }

  const max = Math.max(...groups.flatMap((g) => series.map((s) => g[s.key] ?? 0)), 1)
  const W = 560
  const H = height
  const padB = 30
  const padL = 34
  const padT = 12
  const gw = (W - padL) / groups.length
  const barW = Math.min((gw / (series.length + 1)) * 0.8, 26)
  const plotH = H - padB - padT
  const n = (v) => padT + plotH - ((v / max) * plotH)

  const grid = [0, 0.25, 0.5, 0.75, 1]

  return (
    <div>
      <svg width="100%" viewBox={`0 0 ${W} ${H}`} className="w-full" role="img">
        {grid.map((g) => {
          const y = padT + plotH - g * plotH
          return (
            <g key={g}>
              <line x1={padL} x2={W} y1={y} y2={y} stroke="#e2e8f0" strokeDasharray="3 3" />
              <text x={padL - 6} y={y + 3} textAnchor="end" fontSize="9" fill="#94a3b8">{format(max * g)}</text>
            </g>
          )
        })}
        {groups.map((g, gi) => {
          const gx = padL + gi * gw
          return (
            <g key={gi}>
              {series.map((s, si) => {
                const v = g[s.key] ?? 0
                const x = gx + (gw - series.length * barW - (series.length - 1) * 4) / 2 + si * (barW + 4)
                const y = n(v)
                return (
                  <rect
                    key={si}
                    x={x}
                    y={y}
                    width={barW}
                    height={Math.max(padT + plotH - y, 0)}
                    rx={3}
                    fill={s.color || colorAt(si, series)}
                    tabIndex={onClick ? 0 : undefined}
                    role={onClick ? 'button' : undefined}
                    onClick={onClick ? () => onClick({ label: g.label, value: v, key: s.key, id: g.id }) : undefined}
                    style={onClick ? { cursor: 'pointer' } : undefined}
                  >
                    <title>{`${g.label} · ${s.label}: ${v}`}</title>
                  </rect>
                )
              })}
              <text x={gx + gw / 2} y={H - 10} textAnchor="middle" fontSize="10" fontWeight="700" fill="#475569">
                {groups.length > 5 ? g.label.split(' ')[0] : g.label}
              </text>
            </g>
          )
        })}
      </svg>
      <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2">
        {series.map((s, i) => (
          <span key={s.key} className="flex items-center gap-1.5 text-xs text-slate-500">
            <span className="w-2.5 h-2.5 rounded-sm" style={{ background: s.color || colorAt(i, series) }} />
            {s.label}
          </span>
        ))}
      </div>
    </div>
  )
}