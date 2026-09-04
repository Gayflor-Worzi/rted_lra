<?php

// Permission catalogue for RETD Tasks Management RBAC.
// name format: <module>.<action>

return [

    // Dashboard
    ['module' => 'dashboard', 'action' => 'view_own', 'name' => 'dashboard.view_own', 'description' => 'View own dashboard'],
    ['module' => 'dashboard', 'action' => 'view_section', 'name' => 'dashboard.view_section', 'description' => 'View section dashboard'],
    ['module' => 'dashboard', 'action' => 'view_division', 'name' => 'dashboard.view_division', 'description' => 'View division dashboard'],

    // Bills (RETD Bill Register)
    ['module' => 'bills', 'action' => 'view', 'name' => 'bills.view', 'description' => 'View bills'],
    ['module' => 'bills', 'action' => 'create', 'name' => 'bills.create', 'description' => 'Log a bill'],
    ['module' => 'bills', 'action' => 'edit', 'name' => 'bills.edit', 'description' => 'Edit bill record'],
    ['module' => 'bills', 'action' => 'amend_document', 'name' => 'bills.amend_document', 'description' => 'Amend document number'],
    ['module' => 'bills', 'action' => 'amend_penalty_interest', 'name' => 'bills.amend_penalty_interest', 'description' => 'Amend penalty / interest'],
    ['module' => 'bills', 'action' => 'assign', 'name' => 'bills.assign', 'description' => 'Assign bill to officer'],
    ['module' => 'bills', 'action' => 'reassign', 'name' => 'bills.reassign', 'description' => 'Reassign bill'],
    ['module' => 'bills', 'action' => 'history', 'name' => 'bills.history', 'description' => 'View bill history'],
    ['module' => 'bills', 'action' => 'export', 'name' => 'bills.export', 'description' => 'Export bills'],

    // Tasks
    ['module' => 'tasks', 'action' => 'view_own', 'name' => 'tasks.view_own', 'description' => 'View own tasks'],
    ['module' => 'tasks', 'action' => 'view_section', 'name' => 'tasks.view_section', 'description' => 'View section tasks'],
    ['module' => 'tasks', 'action' => 'view_division', 'name' => 'tasks.view_division', 'description' => 'View division tasks'],
    ['module' => 'tasks', 'action' => 'view_history', 'name' => 'tasks.view_history', 'description' => 'View task history'],
    ['module' => 'tasks', 'action' => 'assign', 'name' => 'tasks.assign', 'description' => 'Assign task'],
    ['module' => 'tasks', 'action' => 'reassign', 'name' => 'tasks.reassign', 'description' => 'Reassign task'],
    ['module' => 'tasks', 'action' => 'priority', 'name' => 'tasks.priority', 'description' => 'Change task priority'],
    ['module' => 'tasks', 'action' => 'complete', 'name' => 'tasks.complete', 'description' => 'Mark task complete'],
    ['module' => 'tasks', 'action' => 'reopen', 'name' => 'tasks.reopen', 'description' => 'Reopen a task'],
    ['module' => 'tasks', 'action' => 'escalate', 'name' => 'tasks.escalate', 'description' => 'Escalate a task'],

    // Enforcement
    ['module' => 'enforcement', 'action' => 'view_assignments', 'name' => 'enforcement.view_assignments', 'description' => 'View assignments'],
    ['module' => 'enforcement', 'action' => 'bill_delivery', 'name' => 'enforcement.bill_delivery', 'description' => 'Perform bill-delivery assignment, delivery and field work'],
    ['module' => 'enforcement', 'action' => 'visit', 'name' => 'enforcement.visit', 'description' => 'Perform enforcement visit'],
    ['module' => 'enforcement', 'action' => 'payment_followup', 'name' => 'enforcement.payment_followup', 'description' => 'Perform payment follow-up'],
    ['module' => 'enforcement', 'action' => 'record_visit', 'name' => 'enforcement.record_visit', 'description' => 'Record field visit'],
    ['module' => 'enforcement', 'action' => 'upload_evidence', 'name' => 'enforcement.upload_evidence', 'description' => 'Upload evidence'],
    ['module' => 'enforcement', 'action' => 'issue_warning', 'name' => 'enforcement.issue_warning', 'description' => 'Issue 30-day / 72-hour warning'],
    ['module' => 'enforcement', 'action' => 'escalate', 'name' => 'enforcement.escalate', 'description' => 'Escalate case'],
    ['module' => 'enforcement', 'action' => 'escalation_override', 'name' => 'enforcement.escalation_override', 'description' => 'Bypass the 72-hour eligibility wait when approving an escalation'],

    // Payment Verification
    ['module' => 'payments', 'action' => 'claim', 'name' => 'payments.claim', 'description' => 'Submit payment claim'],
    ['module' => 'payments', 'action' => 'view_queue', 'name' => 'payments.view_queue', 'description' => 'View verification queue'],
    ['module' => 'payments', 'action' => 'review_receipt', 'name' => 'payments.review_receipt', 'description' => 'Review receipt'],
    ['module' => 'payments', 'action' => 'compare_receipt', 'name' => 'payments.compare_receipt', 'description' => 'Compare receipt / document #'],
    ['module' => 'payments', 'action' => 'verify', 'name' => 'payments.verify', 'description' => 'Verify payment'],
    ['module' => 'payments', 'action' => 'reject', 'name' => 'payments.reject', 'description' => 'Reject payment'],
    ['module' => 'payments', 'action' => 'view_history', 'name' => 'payments.view_history', 'description' => 'View verification history'],
    ['module' => 'payments', 'action' => 'override', 'name' => 'payments.override', 'description' => 'Override verification (restricted)'],

    // Valuation
    ['module' => 'valuation', 'action' => 'prepare', 'name' => 'valuation.prepare', 'description' => 'Prepare a property valuation / reassessment'],
    ['module' => 'valuation', 'action' => 'create', 'name' => 'valuation.create', 'description' => 'Create valuation'],
    ['module' => 'valuation', 'action' => 'edit', 'name' => 'valuation.edit', 'description' => 'Edit valuation'],
    ['module' => 'valuation', 'action' => 'submit', 'name' => 'valuation.submit', 'description' => 'Submit valuation'],
    ['module' => 'valuation', 'action' => 'review', 'name' => 'valuation.review', 'description' => 'Review valuation'],
    ['module' => 'valuation', 'action' => 'return', 'name' => 'valuation.return', 'description' => 'Return to officer'],
    ['module' => 'valuation', 'action' => 'forward_ac', 'name' => 'valuation.forward_ac', 'description' => 'Forward to AC'],
    ['module' => 'valuation', 'action' => 'approve', 'name' => 'valuation.approve', 'description' => 'Approve valuation'],
    ['module' => 'valuation', 'action' => 'reject', 'name' => 'valuation.reject', 'description' => 'Reject valuation'],
    ['module' => 'valuation', 'action' => 'litas_processing', 'name' => 'valuation.litas_processing', 'description' => 'Confirm processed in the source system'],
    ['module' => 'valuation', 'action' => 'view_history', 'name' => 'valuation.view_history', 'description' => 'View valuation history'],

    // Staff
    ['module' => 'staff', 'action' => 'view', 'name' => 'staff.view', 'description' => 'View staff'],
    ['module' => 'staff', 'action' => 'create', 'name' => 'staff.create', 'description' => 'Create staff account'],
    ['module' => 'staff', 'action' => 'edit', 'name' => 'staff.edit', 'description' => 'Edit staff'],
    ['module' => 'staff', 'action' => 'activate', 'name' => 'staff.activate', 'description' => 'Activate / deactivate staff'],
    ['module' => 'staff', 'action' => 'assign_role', 'name' => 'staff.assign_role', 'description' => 'Assign role to staff'],
    ['module' => 'staff', 'action' => 'view_performance', 'name' => 'staff.view_performance', 'description' => 'View staff performance'],

    // RBAC
    ['module' => 'rbac', 'action' => 'create_role', 'name' => 'rbac.create_role', 'description' => 'Create role'],
    ['module' => 'rbac', 'action' => 'edit_role', 'name' => 'rbac.edit_role', 'description' => 'Edit role'],
    ['module' => 'rbac', 'action' => 'deactivate_role', 'name' => 'rbac.deactivate_role', 'description' => 'Deactivate role'],
    ['module' => 'rbac', 'action' => 'assign_permissions', 'name' => 'rbac.assign_permissions', 'description' => 'Assign permissions to role'],
    ['module' => 'rbac', 'action' => 'assign_role_to_user', 'name' => 'rbac.assign_role_to_user', 'description' => 'Assign role to user'],

    // Audit
    ['module' => 'audit', 'action' => 'view', 'name' => 'audit.view', 'description' => 'View audit log'],
    ['module' => 'audit', 'action' => 'export', 'name' => 'audit.export', 'description' => 'Export audit log'],

    // Reports
    ['module' => 'reports', 'action' => 'view', 'name' => 'reports.view', 'description' => 'View reports'],
    ['module' => 'reports', 'action' => 'export', 'name' => 'reports.export', 'description' => 'Export reports'],
    ['module' => 'reports', 'action' => 'print', 'name' => 'reports.print', 'description' => 'Print reports'],

    // Records (linked record registers / drill-down)
    ['module' => 'records', 'action' => 'view', 'name' => 'records.view', 'description' => 'View linked record registers'],

    // New Property Discovery (first-class workflow)
    ['module' => 'discovery', 'action' => 'view', 'name' => 'discovery.view', 'description' => 'View discoveries'],
    ['module' => 'discovery', 'action' => 'create', 'name' => 'discovery.create', 'description' => 'Create discovery'],
    ['module' => 'discovery', 'action' => 'submit', 'name' => 'discovery.submit', 'description' => 'Submit discovery for review'],
    ['module' => 'discovery', 'action' => 'capture_gps', 'name' => 'discovery.capture_gps', 'description' => 'Capture discovery GPS'],
    ['module' => 'discovery', 'action' => 'upload_photo', 'name' => 'discovery.upload_photo', 'description' => 'Upload property photo'],
    ['module' => 'discovery', 'action' => 'review', 'name' => 'discovery.review', 'description' => 'Review discovery'],
    ['module' => 'discovery', 'action' => 'classify', 'name' => 'discovery.classify', 'description' => 'Classify property'],
    ['module' => 'discovery', 'action' => 'route_to_account', 'name' => 'discovery.route_to_account', 'description' => 'Route to Account & Record'],
    ['module' => 'discovery', 'action' => 'route_to_valuation', 'name' => 'discovery.route_to_valuation', 'description' => 'Route to Valuation'],
    ['module' => 'discovery', 'action' => 'approve', 'name' => 'discovery.approve', 'description' => 'Approve discovery'],
    ['module' => 'discovery', 'action' => 'reject', 'name' => 'discovery.reject', 'description' => 'Reject discovery'],
    ['module' => 'discovery', 'action' => 'reopen', 'name' => 'discovery.reopen', 'description' => 'Reopen discovery'],
    ['module' => 'discovery', 'action' => 'litas_processing', 'name' => 'discovery.litas_processing', 'description' => 'Confirm processed in the source system'],

    // M&E
    ['module' => 'me', 'action' => 'view', 'name' => 'me.view', 'description' => 'View M&E queries'],
    ['module' => 'me', 'action' => 'create', 'name' => 'me.create', 'description' => 'Raise M&E query'],
    ['module' => 'me', 'action' => 'respond', 'name' => 'me.respond', 'description' => 'Respond to query'],
    ['module' => 'me', 'action' => 'close', 'name' => 'me.close', 'description' => 'Close query'],
    ['module' => 'me', 'action' => 'assign_walkin', 'name' => 'me.assign_walkin', 'description' => 'Assign walk-in taxpayer case'],
    ['module' => 'me', 'action' => 'reassign', 'name' => 'me.reassign', 'description' => 'Reassign task'],
    ['module' => 'me', 'action' => 'revise', 'name' => 'me.revise', 'description' => 'Revise task'],
    ['module' => 'me', 'action' => 'flag_data_quality', 'name' => 'me.flag_data_quality', 'description' => 'Flag data-quality issue'],
    ['module' => 'me', 'action' => 'review', 'name' => 'me.review', 'description' => 'Use the M&E review board'],
    ['module' => 'me', 'action' => 'responsible', 'name' => 'me.responsible', 'description' => 'Resolve data-quality issues'],

    // Notifications
    ['module' => 'notifications', 'action' => 'view', 'name' => 'notifications.view', 'description' => 'View notifications'],
    ['module' => 'notifications', 'action' => 'broadcast', 'name' => 'notifications.broadcast', 'description' => 'Broadcast notification'],

    // Targets
    ['module' => 'targets', 'action' => 'view', 'name' => 'targets.view', 'description' => 'View staff targets'],
    ['module' => 'targets', 'action' => 'create', 'name' => 'targets.create', 'description' => 'Create staff target'],
    ['module' => 'targets', 'action' => 'edit', 'name' => 'targets.edit', 'description' => 'Edit staff target'],
    ['module' => 'targets', 'action' => 'approve', 'name' => 'targets.approve', 'description' => 'Approve staff target'],
    ['module' => 'targets', 'action' => 'refresh', 'name' => 'targets.refresh', 'description' => 'Refresh achieved metrics'],
];