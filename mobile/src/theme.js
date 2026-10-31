export const theme = {
  colors: {
    primary: '#E00000',        // LRA Red
    primaryDark: '#B00000',
    primaryLight: '#FFE5E5',
    navy: '#002060',           // LRA Navy
    navyLight: '#1A3A80',
    navyBg: '#E8ECF5',
    white: '#FFFFFF',
    bg: '#F5F6FA',
    surface: '#FFFFFF',
    border: '#E5E7EE',
    text: '#0F172A',
    textMuted: '#64748B',
    textLight: '#94A3B8',
    success: '#16A34A',
    successBg: '#E7F6EC',
    warning: '#D97706',
    warningBg: '#FEF3C7',
    danger: '#DC2626',
    dangerBg: '#FEE2E2',
    info: '#2563EB',
    infoBg: '#DBEAFE',
  },
  radius: { sm: 8, md: 12, lg: 16, xl: 24 },
  spacing: { xs: 4, sm: 8, md: 12, lg: 16, xl: 24 },
}

export const statusBadge = (status) => {
  const map = {
    assigned: { bg: '#EFF6FF', fg: '#2563EB' },
    in_progress: { bg: '#FFF7ED', fg: '#D97706' },
    completed: { bg: '#E7F6EC', fg: '#16A34A' },
    overdue: { bg: '#FEE2E2', fg: '#DC2626' },
    escalated: { bg: '#FEF3C7', fg: '#92400E' },
    delivered: { bg: '#E7F6EC', fg: '#16A34A' },
    paid: { bg: '#E7F6EC', fg: '#16A34A' },
    unpaid: { bg: '#FEE2E2', fg: '#DC2626' },
    pending: { bg: '#FEF3C7', fg: '#D97706' },
    submitted: { bg: '#EFF6FF', fg: '#2563EB' },
    approved: { bg: '#E7F6EC', fg: '#16A34A' },
    rejected: { bg: '#FEE2E2', fg: '#DC2626' },
    'assessment in progress': { bg: '#FFF7ED', fg: '#D97706' },
    'awaiting review': { bg: '#EFF6FF', fg: '#2563EB' },
    'submitted to manager': { bg: '#EFF6FF', fg: '#2563EB' },
    'under manager review': { bg: '#DBEAFE', fg: '#1D4ED8' },
    'awaiting ac': { bg: '#FEF3C7', fg: '#D97706' },
    'submitted to ac': { bg: '#FEF3C7', fg: '#D97706' },
    'awaiting ac approval': { bg: '#FEF3C7', fg: '#D97706' },
    'ac approved': { bg: '#E7F6EC', fg: '#16A34A' },
    'returned for correction': { bg: '#FFF7ED', fg: '#D97706' },
    'ac returned': { bg: '#FFF7ED', fg: '#D97706' },
    'ac rejected': { bg: '#FEE2E2', fg: '#DC2626' },
    'awaiting account & record': { bg: '#EFF6FF', fg: '#2563EB' },
    'sent to account & record': { bg: '#EFF6FF', fg: '#2563EB' },
    registered: { bg: '#E8ECF5', fg: '#002060' },
  }
  return map[status?.toLowerCase?.()] || { bg: '#F1F5F9', fg: '#475569' }
}