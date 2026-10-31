// Property Description row arithmetic for the Valuation Assessment form.
//
// The same rules are enforced server-side by ValuationPropertyDescription; this
// module keeps the form's live totals identical to what the server will store.
//
//   Amount        Land: market value per lot × number of lots
//                 Other: area (sq ft) × TAR rate × quantity
//   Depreciation  Land: 0%
//                 Other: building age (years) × 2%, straight-line on the Amount
//   Row value     Amount × (1 − Depreciation% ÷ 100)
//
// Quantity is already inside the Amount, so it is never applied a second time.

export const LAND_LEVEL = 'Land'
export const DEPRECIATION_RATE_PCT = 2

/** Land is identified by its level, and is always the first row. */
export const isLandRow = (row) => String(row?.level ?? '').trim().toLowerCase() === LAND_LEVEL.toLowerCase()

/** Keeps money at cent precision so a total never drifts by a fraction of a cent. */
export const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100

/** A blank or unparsable input is missing — not zero. */
const toNum = (v) => {
  if (v === '' || v === null || v === undefined) return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

export const rowQuantity = (row) => {
  const q = toNum(row?.quantity)
  return q === null || q < 1 ? 1 : Math.floor(q)
}

/** Amount in US$, or null when a required input is still missing. */
export const rowAmount = (row) => {
  const tar = toNum(row?.tar)
  if (tar === null || tar < 0) return null
  const qty = rowQuantity(row)

  if (isLandRow(row)) return round2(tar * qty)

  const area = toNum(row?.area_sqft)
  if (area === null || area < 0) return null
  return round2(area * tar * qty)
}

/** Age × 2% for buildings; Land is never depreciated. */
export const rowDepreciationPct = (row) => {
  if (isLandRow(row)) return 0
  const age = toNum(row?.building_age)
  if (age === null || age < 0) return 0
  return round2(Math.min(100, age * DEPRECIATION_RATE_PCT))
}

/** Row assessed value in US$, or null when the row cannot be priced yet. */
export const rowValue = (row) => {
  const amount = rowAmount(row)
  if (amount === null) return null
  return round2(amount * (1 - rowDepreciationPct(row) / 100))
}

export const totalAssessedValue = (rows) => round2(rows.reduce((sum, row) => sum + (rowValue(row) ?? 0), 0))

/**
 * Values the form must refuse outright: negative or malformed inputs. A blank
 * field is not an error here — a draft may be saved half-filled — but it is
 * never priced as zero, and the server refuses to submit an incomplete Land row
 * or an unpriced building row.
 */
export const descriptionInputIssues = (rows) => {
  const issues = []

  rows.forEach((row, i) => {
    const label = isLandRow(row) ? 'Land' : `Row ${i + 1}`

    const tar = toNum(row.tar)
    if (tar !== null && tar < 0) issues.push(`${label}: the rate cannot be negative.`)

    const qty = toNum(row.quantity)
    if (qty !== null && qty < 1) issues.push(`${label}: the quantity must be at least 1.`)

    if (isLandRow(row)) return

    const area = toNum(row.area_sqft)
    if (area !== null && area < 0) issues.push(`${label}: the area cannot be negative.`)

    const age = toNum(row.building_age)
    if (age !== null && age < 0) issues.push(`${label}: the building age cannot be negative.`)
  })

  return issues
}
