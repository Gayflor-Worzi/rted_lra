// Shared domain constants for the RETD Tasks Management web console.

export const TASK_STATUSES = [
  'Logged',
  'Awaiting Assignment',
  'Assigned',
  'Out for Delivery',
  'Delivered',
  'Payment Follow-up',
  'Payment Claimed',
  'Verification Pending',
  'Payment Verification',
  '30-Day Warning',
  '72-Hour Warning',
  'Escalated',
  'Paid',
  'Partially Paid',
  'Outstanding',
  'Payment Rejected',
  'Resolved',
  'Closed',
]

// Task -> machine state machine (mirror of Task::TRANSITIONS).
export const TASK_TRANSITIONS = {
  Logged: ['Awaiting Assignment', 'Assigned', 'Closed'],
  'Awaiting Assignment': ['Assigned', 'Closed'],
  Assigned: ['Out for Delivery', 'Delivered', 'Payment Follow-up', 'Escalated'],
  'Out for Delivery': ['Delivered', 'Assigned', 'Escalated'],
  Delivered: ['Payment Follow-up', 'Escalated', 'Closed'],
  'Payment Follow-up': ['Payment Claimed', 'Verification Pending', '30-Day Warning', 'Escalated', 'Closed'],
  'Payment Claimed': ['Verification Pending', 'Payment Follow-up'],
  'Verification Pending': ['Payment Verification', 'Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
  'Payment Verification': ['Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up'],
  '30-Day Warning': ['72-Hour Warning', 'Escalated', 'Payment Follow-up'],
  '72-Hour Warning': ['Escalated', 'Payment Follow-up'],
  Escalated: ['Payment Follow-up', 'Closed'],
  Paid: ['Resolved', 'Closed'],
  'Partially Paid': ['Payment Follow-up', 'Closed'],
  Outstanding: ['30-Day Warning', 'Escalated'],
  'Payment Rejected': ['Payment Follow-up', 'Escalated'],
  Resolved: ['Closed'],
  Closed: [],
}

// Friendly action label for a transition target.
export const TRANSITION_ACTION = {
  'Awaiting Assignment': 'queue',
  Assigned: 'assign',
  'Out for Delivery': 'dispatch',
  Delivered: 'delivered',
  'Payment Follow-up': 'follow-up',
  'Payment Claimed': 'claim',
  'Verification Pending': 'verify-pending',
  'Payment Verification': 'verify',
  Paid: 'mark-paid',
  'Partially Paid': 'mark-partial',
  Outstanding: 'mark-outstanding',
  'Payment Rejected': 'reject-payment',
  '30-Day Warning': 'warning-30',
  '72-Hour Warning': 'warning-72',
  Escalated: 'escalate',
  Resolved: 'resolve',
  Closed: 'close',
}

// Canonical option lists for form dropdowns (shared across all forms).
export const PROPERTY_CLASSIFICATIONS = [
  'Residential',
  'Commercial',
  'Industrial',
  'Unimproved Land',
  'Residential Building on public land', 
  'Commercial Building on public land', 
  'Developed Land Residential',
  'Vacant land',
  'Mixed Use',
]

export const PROPERTY_TYPES = [
  'New Property',
  'Existing Property',
]

// Everything must remain in USD.
export const CURRENCY = 'USD'

export const DELIVERY_STATUSES = [
  'Logged',
  'Out for Delivery',
  'Delivered',
  'Returned',
  'Filed',
]

// Bill delivery outcome captured during a field visit.
export const BILL_DELIVERY_STATUSES = [
  'Delivered',
  'Undelivered',
  'Returned to Office',
  'Not Mailable',
]

// Outcome of an enforcement field visit (drives the visit status note).
export const VISIT_STATUSES = [
  'Visited - Delivered',
  'Visited - Payment Follow-up',
  'Premises Locked',
  'Business Closed',
  'Subject Not Found',
  'Refused Service',
  'Follow-up Scheduled',
]

// Unified engagement log — one entry per attempt/notice/claim/verification on a
// task timeline (mirror of TaskEngagement::TYPES).
export const ENGAGEMENT_INFO = {
  delivery_attempt:   { label: 'Delivery attempt', tone: 'blue', icon: '📍' },
  bill_delivered:     { label: 'Bill delivered', tone: 'green', icon: '📮' },
  follow_up:          { label: 'Follow-up', tone: 'brand', icon: '📞' },
  reminder_30_day:    { label: '30-Day Reminder', tone: 'amber', icon: '📅' },
  demand_72_hour:     { label: '72-Hour Demand', tone: 'amber', icon: '⏱️' },
  final_enforcement:  { label: 'Final enforcement', tone: 'red', icon: '🚨' },
  closure:            { label: 'Closure', tone: 'slate', icon: '🔒' },
  payment_claim:      { label: 'Payment claim', tone: 'navy', icon: '🧾' },
  verification:       { label: 'Payment verification', tone: 'blue', icon: '✅' },
  payment_confirmed:  { label: 'Payment confirmed', tone: 'green', icon: '💰' },
  assignment:         { label: 'Assignment', tone: 'slate', icon: '👤' },
  note:               { label: 'Note', tone: 'slate', icon: '🗒️' },
}

export const engagementInfo = (t) => ENGAGEMENT_INFO[t] || { label: t, tone: 'slate', icon: '•' }

export const ENGAGEMENT_TYPES = Object.keys(ENGAGEMENT_INFO)

export const PAYMENT_STATUSES = [
  'Unpaid',
  'Payment Claimed',
  'Verification Pending',
  'Partially Paid',
  'Paid',
  'Payment Rejected',
  'Payment Mismatch',
]

export const CASE_STATUSES = [
  'Logged',
  'Awaiting Assignment',
  'Assigned',
  'Out for Delivery',
  'Delivered',
  'Payment Follow-up',
  '30-Day Warning',
  '72-Hour Warning',
  'Escalated',
  'Under Verification',
  'Resolved',
  'Closed',
]

// New Property Discovery — first-class workflow statuses (mirror of
// PropertyDiscovery::STATUSES). Classification selects Path A (account) or
// Path V (valuation); LITAS identifiers are recorded, never generated.
export const DISCOVERY_STATUSES = [
  'DISCOVERED',
  'SUBMITTED',
  'UNDER_MANAGER_REVIEW',
  'CLASSIFIED',
  'SENT_TO_ACCOUNT',
  'VALUATION_REQUIRED',
  'VALUATION_ASSIGNED',
  'UNDER_VALUATION',
  'VALUATION_MANAGER_REVIEW',
  'PENDING_AC_APPROVAL',
  'AC_APPROVED',
  'AC_REJECTED',
  'RETURNED_FOR_CORRECTION',
  'RESUBMITTED',
  'SENT_TO_ACCOUNT_MANAGER',
  'PROCESSED_IN_LITAS',
  'COMPLETED',
]

export const DISCOVERY_PATHS = [
  { value: 'account', label: 'Path A — Account & Records' },
  { value: 'valuation', label: 'Path V — Valuation' },
]

export const DISCOVERY_ROUTE_LABEL = {
  account: 'Path A · Account & Records',
  valuation: 'Path V · Valuation',
}

// Display label + tone for each discovery status.
export const discoveryStatusInfo = (s) => ({
  DISCOVERED: { label: 'Discovered', tone: 'slate' },
  SUBMITTED: { label: 'Submitted', tone: 'blue' },
  UNDER_MANAGER_REVIEW: { label: 'Under Review', tone: 'amber' },
  CLASSIFIED: { label: 'Classified', tone: 'navy' },
  SENT_TO_ACCOUNT: { label: 'Sent to Account', tone: 'blue' },
  VALUATION_REQUIRED: { label: 'Valuation Required', tone: 'navy' },
  VALUATION_ASSIGNED: { label: 'Valuation Assigned', tone: 'blue' },
  UNDER_VALUATION: { label: 'Under Valuation', tone: 'amber' },
  VALUATION_MANAGER_REVIEW: { label: 'Valuation Review', tone: 'amber' },
  PENDING_AC_APPROVAL: { label: 'Pending AC Approval', tone: 'amber' },
  AC_APPROVED: { label: 'AC Approved', tone: 'green' },
  AC_REJECTED: { label: 'AC Rejected', tone: 'red' },
  RETURNED_FOR_CORRECTION: { label: 'Returned for Correction', tone: 'red' },
  RESUBMITTED: { label: 'Resubmitted', tone: 'blue' },
  SENT_TO_ACCOUNT_MANAGER: { label: 'Sent to Account Manager', tone: 'blue' },
  PROCESSED_IN_LITAS: { label: 'Processed in LITAS', tone: 'navy' },
  COMPLETED: { label: 'Completed', tone: 'green' },
}[s] || { label: s, tone: 'slate' })

// Staff Target metrics grouped by the staff function they belong to, with a
// friendly label (mirror of StaffTarget::METRICS).
export const TARGET_METRICS = {
  'Account & Record': [
    { value: 'bills_logged', label: 'Bills logged' },
    { value: 'bills_processed', label: 'Bills processed' },
    { value: 'payment_verifications', label: 'Payment verifications' },
    { value: 'records_amended', label: 'Records amended' },
    { value: 'data_quality_completed', label: 'Data quality fixes' },
  ],
  Enforcement: [
    { value: 'bills_delivered', label: 'Bills delivered' },
    { value: 'visits', label: 'Field visits' },
    { value: 'payment_followups', label: 'Payment follow-ups' },
    { value: 'reminder_notices', label: 'Reminder notices' },
    { value: 'hour_72_demands', label: '72-hour demands' },
    { value: 'enforcement_cases', label: 'Enforcement cases' },
    { value: 'completed_tasks', label: 'Tasks completed' },
  ],
  Valuation: [
    { value: 'valuations', label: 'Valuations' },
    { value: 'reassessments', label: 'Reassessments' },
    { value: 'valuation_corrections', label: 'Valuation corrections' },
    { value: 'approved_valuations', label: 'Valuations approved' },
  ],
  'M&E': [
    { value: 'reports_completed', label: 'Reports completed' },
    { value: 'tasks_reviewed', label: 'Tasks reviewed' },
    { value: 'monitoring_activities', label: 'Monitoring activities' },
    { value: 'data_quality_checks', label: 'Data quality checks' },
    { value: 'performance_reports', label: 'Performance reports' },
    { value: 'walkin_assignments', label: 'Walk-in assignments' },
  ],
  Financial: [
    { value: 'collections_amount', label: 'Collections amount' },
    { value: 'custom', label: 'Custom indicator' },
  ],
}

export const targetMetricLabel = (v) => {
  for (const group of Object.values(TARGET_METRICS)) {
    const hit = group.find((m) => m.value === v)
    if (hit) return hit.label
  }
  return v
}

export const TARGET_FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual']

const tone = (set) => (s) => (set[s] || 'slate')

export const taskTone = tone({
  Paid: 'green', Resolved: 'green', Closed: 'slate', Delivered: 'blue',
  'Out for Delivery': 'blue', Assigned: 'blue', Logged: 'slate',
  Escalated: 'red', 'Payment Rejected': 'red',
  '30-Day Warning': 'amber', '72-Hour Warning': 'amber', 'Verification Pending': 'amber',
  'Awaiting Assignment': 'navy', 'Payment Follow-up': 'brand', 'Outstanding': 'amber',
})

export const billCaseTone = tone({
  Paid: 'green', Resolved: 'green', Closed: 'slate', Delivered: 'blue',
  'Out for Delivery': 'blue', Assigned: 'blue',
  Escalated: 'red', '30-Day Warning': 'amber', '72-Hour Warning': 'amber',
  'Awaiting Assignment': 'navy', 'Under Verification': 'brand',
})

export const fmtMoney = (n) => 'US$ ' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const fmtDate = (d) => (d ? String(d).slice(0, 10) : '—')
export const fmtTime = (d) => (d ? String(d).replace('T', ' ').slice(0, 16) : '—')