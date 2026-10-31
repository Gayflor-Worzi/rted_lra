export function performanceProgress(value) {
  if (value === null || value === undefined || value === '') {
    return { label: 'N/A', width: null }
  }

  const percentage = Number(value)
  if (!Number.isFinite(percentage)) {
    return { label: 'N/A', width: null }
  }

  return {
    label: `${Number(percentage.toFixed(1))}%`,
    width: Math.min(Math.max(percentage, 0), 100),
  }
}
