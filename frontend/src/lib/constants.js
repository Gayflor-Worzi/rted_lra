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
  'Undelivered',
  'Not Mailable',
  'Filed',
]

// Bill delivery outcome captured during a field visit.
export const BILL_DELIVERY_STATUSES = [
  'Out for Delivery',
  'Delivered',
  'Returned',
  'Undelivered',
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

// New Property Discovery — controlled workflow statuses (mirror of
// PropertyDiscovery::STATUSES). Auto-classifies on submit to Path A or V;
// Source-system identifiers are recorded, never generated.
export const DISCOVERY_STATUSES = [
  'Draft',
  'Submitted',
  'Classified',
  'Awaiting Account Manager Review',
  'Awaiting Valuation',
  'Under Valuation',
  'Awaiting AC Approval',
  'Approved',
  'Returned for Correction',
  'Assigned to Account & Record',
  'Referred to Valuation',
  'Registered',
  'Completed',
  'Rejected',
  'Closed',
]

export const DISCOVERY_PATHS = [
  { value: 'account', label: 'Path A — Account & Records' },
  { value: 'valuation', label: 'Path V — Valuation' },
]

// System-wide Value Declaration vocabulary (§2) — the three controlled values.
export const VALUE_DECLARATION_TYPES = [
  { value: 'SELF_DECLARED', label: 'Self Declared', tone: 'green', hint: 'Owner-declared value — goes to the Account & Record Manager.' },
  { value: 'DOCUMENTARY_VALUE', label: 'Value from Documentation', tone: 'blue', hint: 'Value from deed/documents — Account & Record Manager with document review.' },
  { value: 'VALUATION_VALUE', label: 'Valuation', tone: 'amber', hint: 'Formal valuation required — routed to the Valuation Manager. You cannot enter a value.' },
]

export const valueDeclarationInfo = (v) =>
  VALUE_DECLARATION_TYPES.find((t) => t.value === v) || { value: v, label: v || 'Not declared', tone: 'slate', hint: '' }

export const DISCOVERY_ROUTE_LABEL = {
  account: 'Path A · Account & Records',
  valuation: 'Path V · Valuation',
}

// Display label + tone for each discovery status (keys match backend human-readable values).
export const discoveryStatusInfo = (s) => ({
  'Draft':                                  { label: 'Draft',                             tone: 'slate' },
  'Submitted':                              { label: 'Submitted',                         tone: 'blue' },
  'Classified':                             { label: 'Classified',                        tone: 'navy' },
  'Awaiting Account Manager Review':       { label: 'Awaiting A&R Review',               tone: 'amber' },
  'Awaiting Valuation':                     { label: 'Awaiting Valuation',                tone: 'amber' },
  'Under Valuation':                        { label: 'Under Valuation',                   tone: 'blue' },
  'Awaiting AC Approval':                   { label: 'Awaiting AC Approval',              tone: 'amber' },
  'Approved':                               { label: 'Approved',                          tone: 'green' },
  'Returned for Correction':                { label: 'Returned for Correction',           tone: 'red' },
  'Assigned to Account & Record':            { label: 'Assigned to A&R — processing',     tone: 'blue' },
  'Referred to Valuation':                  { label: 'Referred to Valuation',             tone: 'blue' },
  'Registered':                     { label: 'Registered',                tone: 'navy' },
  'Completed':                              { label: 'Completed',                         tone: 'green' },
  'Rejected':                               { label: 'Rejected',                          tone: 'red' },
  'Closed':                                 { label: 'Closed',                            tone: 'slate' },
}[s] || { label: s, tone: 'slate' })

// Valuation assessment statuses (mirror of Valuation::STATUSES).
export const VALUATION_STATUSES = [
  'Assigned',
  'Assessment in Progress',
  'Submitted to Manager',
  'Under Manager Review',
  'Returned for Correction',
  'Submitted to AC',
  'Awaiting AC Approval',
  'AC Approved',
  'AC Returned',
  'AC Rejected',
  'Sent to Account & Record',
  'Registered',
  'Completed',
]

// Display label + tone for valuation statuses.
export const valuationStatusInfo = (s) => ({
  'Assigned':                   { label: 'Assigned',                   tone: 'slate' },
  'Assessment in Progress':     { label: 'In Progress',                tone: 'blue' },
  'Submitted to Manager':       { label: 'Submitted to Manager',       tone: 'amber' },
  'Under Manager Review':       { label: 'Under Manager Review',       tone: 'amber' },
  'Returned for Correction':    { label: 'Returned for Correction',    tone: 'red' },
  'Submitted to AC':            { label: 'Submitted to AC',            tone: 'blue' },
  'Awaiting AC Approval':       { label: 'Awaiting AC Approval',       tone: 'amber' },
  'AC Approved':                { label: 'AC Approved',                tone: 'green' },
  'AC Returned':                { label: 'AC Returned',                tone: 'red' },
  'AC Rejected':                { label: 'AC Rejected',                tone: 'red' },
  'Sent to Account & Record':   { label: 'Sent to A&R',               tone: 'blue' },
  'Registered':         { label: 'Registered',         tone: 'navy' },
  'Completed':                  { label: 'Completed',                  tone: 'green' },
}[s] || { label: s, tone: 'slate' })

// The full valuation stage ladder for the workflow stepper (mirrors valuation statuses in order).
export const VALUATION_LADDER = [
  'Assigned',
  'Assessment in Progress',
  'Submitted to Manager',
  'Under Manager Review',
  'Returned for Correction',
  'Submitted to AC',
  'Awaiting AC Approval',
  'AC Approved',
  'AC Returned',
  'AC Rejected',
  'Sent to Account & Record',
  'Registered',
  'Completed',
]

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