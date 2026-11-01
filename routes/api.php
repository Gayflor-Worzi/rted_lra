<?php

use App\Http\Controllers\Api\AccountTaskController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AssistantCommissionerDashboardController;
use App\Http\Controllers\Api\AssistantCommissionerTaskController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AdminPasswordRecoveryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillRegisterController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardDataController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\EnforcementController;
use App\Http\Controllers\Api\EnforcementOrganizationController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\ManagerDashboardController;
use App\Http\Controllers\Api\ManagementPerformanceController;
use App\Http\Controllers\Api\MEController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OfficerDashboardController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\PropertyRecordExportController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SectionsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TargetsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\ValuationManagerTaskController;
use App\Http\Controllers\Api\ValuationsController;
use App\Models\Role;
use App\Services\StatusEnumeration;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RETD Tasks Management API  (LRA — Domestic Tax Department — RETD)
|--------------------------------------------------------------------------
| Source-system boundary: Document # and Property ID originate in the source
| system. This application logs, stores, displays and searches them — it
| never generates them, never generates tax bills, and never claims live
| integration with the source system.
|
*/
Route::get('/v1/meta', fn () => response()->json([
    'data' => [
        'app' => config('app.name'),
        'source_of_bill' => 'Source Tax System',
        'source_of_document_number' => 'Source Tax System',
        'source_of_property_id' => 'Source Tax System',
        'generates_tax_bills' => false,
        'generates_document_numbers' => false,
        'generates_property_ids' => false,
        'connected_to_source_system' => false,
    ],
]));

Route::post('/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
Route::post('/v1/auth/password-recovery/request', [AdminPasswordRecoveryController::class, 'requestOtp']);
Route::post('/v1/auth/password-recovery/verify', [AdminPasswordRecoveryController::class, 'verifyOtp']);
Route::post('/v1/auth/password-recovery/complete', [AdminPasswordRecoveryController::class, 'completeReset']);

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/auth/effective-permissions', [AuthController::class, 'permissions']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

        // Lookups
        Route::get('/lookup/roles', function () {
            $user = auth()->user();

            $roles = Role::where('is_active', true)
                ->when(! $user->isSystemAdministrator(), fn ($q) => $q->whereIn('name', [
                    'Account & Records Officer', 'Account Supervisor', 'Enforcement Officer',
                    'Enforcement Supervisor', 'M&E Officer', 'Valuation Officer', 'Valuation Supervisor',
                ]))
                ->orderBy('name')
                ->get(['id', 'name', 'default_scope']);

            return response()->json(['data' => $roles]);
        });

        Route::get('/sections', [SectionsController::class, 'index']);
        Route::get('/lookup/supervisors', [UsersController::class, 'supervisors']);
        Route::get('/lookup/enforcement-officers', [UsersController::class, 'enforcementOfficers']);
        Route::get('/lookup/assignable', [UsersController::class, 'assignable']);

        // Single source of truth for all enums — eliminates vocabulary drift
        // (H5, W/M constants). Frontends MUST consume at boot rather than hard-coding.
        Route::get('/lookup/statuses', function () {
            $user = auth()->user();
            abort_unless($user || true, 401);

            return response()->json(['data' => StatusEnumeration::all()]);
        });

        // Dashboard data — single authoritative pipeline.
        // Accepts optional filter overrides; always returns KPIs + charts + tables
        // derived from the same filtered, authorization-scoped dataset.
        Route::get('/dashboard/data', [DashboardDataController::class, 'data']);

        // Staff management
        Route::get('/users', [UsersController::class, 'index']);
        Route::post('/users', [UsersController::class, 'store'])->middleware('permission:staff.create');
        Route::put('/users/{user}', [UsersController::class, 'update'])->middleware('permission:staff.edit');
        Route::patch('/users/{user}/active', [UsersController::class, 'setActive'])->middleware('permission:staff.activate');
        Route::post('/users/{user}/force-password-reset', [UsersController::class, 'forcePasswordReset'])->middleware('permission:staff.edit');
        Route::get('/users/{user}/effective-permissions', [UsersController::class, 'effectivePermissions']);
        Route::put('/users/{user}/permissions', [UsersController::class, 'setPermissions']);

        // RBAC administration
        Route::get('/admin/roles', [RolesController::class, 'index']);
        Route::get('/admin/permission-catalog', [RolesController::class, 'permissions']);
        Route::post('/admin/roles', [RolesController::class, 'store'])->middleware('permission:rbac.create_role');
        Route::post('/admin/roles/{role}/clone', [RolesController::class, 'clone'])->middleware('permission:rbac.create_role');
        Route::put('/admin/roles/{role}', [RolesController::class, 'update'])->middleware('permission:rbac.assign_permissions');

        // Bills — RETD Bill Register (source-system identifiers)
        Route::get('/property-bills', [BillRegisterController::class, 'index']);
        Route::post('/property-bills', [BillRegisterController::class, 'store']);
        Route::get('/property-bills/lookup', [BillRegisterController::class, 'lookup']);
        Route::get('/property-bills/{property_bill}', [BillRegisterController::class, 'show']);
        Route::put('/property-bills/{property_bill}', [BillRegisterController::class, 'update']);
        Route::post('/property-bills/{property_bill}/assign', [BillRegisterController::class, 'assign']);
        Route::post('/property-bills/{property_bill}/amend', [BillRegisterController::class, 'amend']);
        Route::get('/property-bills/{property_bill}/amendments', [BillRegisterController::class, 'amendments']);
        Route::get('/bill-amendments', [BillRegisterController::class, 'billAmendments']);

        // Global search (Document #, Property ID, TIN, taxpayer, address)
        Route::get('/search', [BillRegisterController::class, 'search']);

        // Unified task engine
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/my', [TaskController::class, 'my']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        Route::post('/tasks/{task}/transition', [TaskController::class, 'transition']);
        Route::post('/tasks/{task}/assign', [TaskController::class, 'assign']);
        Route::post('/tasks/{task}/advance', [TaskController::class, 'advance']);
        Route::get('/tasks/{task}/engagements', [TaskController::class, 'engagements']);
        Route::post('/tasks/{task}/engagements', [TaskController::class, 'recordEngagement']);

        // Dashboard
        Route::get('/dashboard/my', [DashboardController::class, 'my']);
        Route::get('/dashboard/performance/management', [ManagementPerformanceController::class, 'summary']);
        Route::get('/dashboard/my/export', [DashboardController::class, 'exportMy']);
        Route::get('/dashboard/division', [DashboardController::class, 'division']);
        Route::get('/dashboard/analytics', [AnalyticsController::class, 'summary']);
        Route::get('/dashboard/analytics/export', [AnalyticsController::class, 'export']);
        Route::get('/dashboard/officer', [OfficerDashboardController::class, 'summary']);
        Route::get('/dashboard/officer/export', [OfficerDashboardController::class, 'export']);
        Route::get('/dashboard/manager/account', [ManagerDashboardController::class, 'account']);
        Route::get('/dashboard/manager/valuation', [ManagerDashboardController::class, 'valuation']);
        Route::get('/dashboard/manager/enforcement', [ManagerDashboardController::class, 'enforcement']);
        Route::get('/enforcement/organization', [EnforcementOrganizationController::class, 'index']);
        Route::post('/enforcement/organization/recommendations', [EnforcementOrganizationController::class, 'store']);
        Route::post('/enforcement/organization/recommendations/{recommendation}/approve', [EnforcementOrganizationController::class, 'approve']);
        Route::post('/enforcement/organization/recommendations/{recommendation}/reject', [EnforcementOrganizationController::class, 'reject']);
        Route::get('/dashboard/ac', [AssistantCommissionerDashboardController::class, 'overview']);
        Route::get('/dashboard/drill', [DashboardController::class, 'drill']);
        Route::get('/dashboard/integrity', [DashboardController::class, 'integrity']);

        // Account & Record Officer — My Tasks module (own-scope, no enforcement dependency)
        Route::get('/account/my-tasks/summary', [AccountTaskController::class, 'summary']);
        Route::get('/account/my-tasks', [AccountTaskController::class, 'assigned']);
        Route::get('/account/my-tasks/discoveries', [AccountTaskController::class, 'discoveries']);
        Route::get('/account/my-tasks/badge', [AccountTaskController::class, 'badge']);
        Route::post('/account/my-tasks/read', [AccountTaskController::class, 'readAll']);

        // Assistant Commissioner — own section workspace (My Tasks / Decision Queue)
        Route::get('/ac/tasks/summary', [AssistantCommissionerTaskController::class, 'summary']);
        Route::get('/ac/tasks', [AssistantCommissionerTaskController::class, 'assigned']);
        Route::get('/ac/tasks/queue', [AssistantCommissionerTaskController::class, 'queue']);
        Route::get('/ac/tasks/badge', [AssistantCommissionerTaskController::class, 'badge']);
        Route::post('/ac/tasks/read', [AssistantCommissionerTaskController::class, 'readAll']);

        // Valuation — own section workspace (My Tasks / Manager Review Queue)
        Route::get('/valuation/tasks/summary', [ValuationManagerTaskController::class, 'summary']);
        Route::get('/valuation/tasks', [ValuationManagerTaskController::class, 'assigned']);
        Route::get('/valuation/tasks/queue', [ValuationManagerTaskController::class, 'queue']);
        Route::get('/valuation/tasks/badge', [ValuationManagerTaskController::class, 'badge']);
        Route::post('/valuation/tasks/read', [ValuationManagerTaskController::class, 'readAll']);

        // New Property Discovery (first-class workflow)
        Route::get('/discoveries', [DiscoveryController::class, 'index']);
        Route::post('/discoveries', [DiscoveryController::class, 'store']);
        Route::get('/discoveries/stats', [DiscoveryController::class, 'stats']);
        Route::get('/property-profile', [DiscoveryController::class, 'profile']);
        Route::get('/discoveries/{discovery}', [DiscoveryController::class, 'show']);
        Route::put('/discoveries/{discovery}', [DiscoveryController::class, 'update']);
        Route::post('/discoveries/{discovery}/submit', [DiscoveryController::class, 'submit']);
        Route::post('/discoveries/{discovery}/resubmit', [DiscoveryController::class, 'resubmit']);
        Route::post('/discoveries/{discovery}/acct-review', [DiscoveryController::class, 'acctReview']);
        Route::post('/discoveries/{discovery}/val-review', [DiscoveryController::class, 'valReview']);
        Route::post('/discoveries/{discovery}/account-processing', [DiscoveryController::class, 'accountProcessing']);
        Route::post('/discoveries/{discovery}/complete', [DiscoveryController::class, 'complete']);
        Route::post('/discoveries/{discovery}/reopen', [DiscoveryController::class, 'reopen']);
        Route::post('/discoveries/{discovery}/evidence', [DiscoveryController::class, 'storeEvidence']);
        Route::get('/discoveries/{discovery}/evidence/{evidence}/file', [DiscoveryController::class, 'evidenceFile']);
        Route::delete('/discoveries/{discovery}/evidence/{evidence}', [DiscoveryController::class, 'deleteEvidence']);

        // Staff targets (performance management)
        Route::get('/targets', [TargetsController::class, 'index']);
        Route::post('/targets', [TargetsController::class, 'store']);
        Route::put('/targets/{target}', [TargetsController::class, 'update']);
        Route::post('/targets/{target}/approve', [TargetsController::class, 'approve']);
        Route::post('/targets/refresh/{target?}', [TargetsController::class, 'refresh']);

        // Notifications
        Route::get('/notifications', [NotificationsController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationsController::class, 'unreadCount']);
        Route::post('/notifications/{notification}/read', [NotificationsController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsController::class, 'readAll']);
        Route::post('/notifications/broadcast', [NotificationsController::class, 'broadcast']);

        // Enforcement (field assignments, visits, discovery, claims)
        Route::get('/enforcement-assignments/my', [EnforcementController::class, 'myAssignments']);
        Route::post('/enforcement-assignments/{task}/action', [EnforcementController::class, 'assignmentAction']);
        Route::post('/enforcement-visits', [EnforcementController::class, 'storeVisit']);
        Route::get('/enforcement-visits', [EnforcementController::class, 'visitsIndex']);
        Route::post('/enforcement/discover', [EnforcementController::class, 'discover']);
        Route::post('/enforcement/submit-receipt', [EnforcementController::class, 'submitReceipt']);

        // Sync ledger (offline-first field submissions)
        Route::get('/sync/transactions', [SyncController::class, 'transactions']);

        // GPS / property location
        Route::get('/gps/capture-context/{propertyId}', [GpsController::class, 'captureContext']);
        Route::get('/gps/property/{propertyId}/current', [GpsController::class, 'current']);
        Route::get('/gps/property/{propertyId}/history', [GpsController::class, 'history']);
        Route::get('/gps/registry', [GpsController::class, 'registry']);
        Route::get('/gps/my-submissions', [GpsController::class, 'mySubmissions']);
        Route::post('/gps/certify', [GpsController::class, 'certify']);
        Route::get('/gps/reviews', [GpsController::class, 'pendingReviews']);
        Route::post('/gps/reviews/{history}/decision', [GpsController::class, 'decision']);
        Route::put('/gps/property/{propertyId}/correct', [GpsController::class, 'correct']);

        // Payments / verification
        Route::get('/payments/queue', [PaymentsController::class, 'queue']);
        Route::get('/payments/history', [PaymentsController::class, 'history']);
        Route::get('/payments/verifications/{verification}/receipt', [PaymentsController::class, 'receipt']);
        Route::get('/payments/verifications/{verification}/receipt-file', [PaymentsController::class, 'receiptFile']);
        Route::post('/payments/verifications/{verification}/confirm', [PaymentsController::class, 'confirm']);
        Route::post('/payments/verifications/{verification}/reject', [PaymentsController::class, 'reject']);
        Route::post('/payments/duplicate-check', [PaymentsController::class, 'duplicateCheck']);

        // Valuations
        Route::get('/valuations', [ValuationsController::class, 'index']);
        Route::post('/valuations', [ValuationsController::class, 'store']);
        Route::get('/valuations/{valuation}', [ValuationsController::class, 'show']);
        Route::put('/valuations/{valuation}', [ValuationsController::class, 'update']);
        Route::post('/valuations/{valuation}/submit', [ValuationsController::class, 'submit']);
        Route::post('/valuations/{valuation}/review', [ValuationsController::class, 'review']);
        Route::post('/valuations/{valuation}/assign', [ValuationsController::class, 'assign']);
        Route::post('/valuations/{valuation}/decide', [ValuationsController::class, 'decide']);
        Route::post('/valuations/{valuation}/account-review', [ValuationsController::class, 'accountReview']);
        Route::post('/valuations/{valuation}/begin', [ValuationsController::class, 'begin']);
        Route::post('/valuations/{valuation}/processing', [ValuationsController::class, 'processing']);
        Route::post('/valuations/{valuation}/complete', [ValuationsController::class, 'complete']);
        Route::post('/valuations/{valuation}/descriptions', [ValuationsController::class, 'descriptions']);

        // Property Record export/print (official A4 landscape sheet).
        // Authorization is enforced inside the controller: property.export plus the
        // same record scope the Property View uses, re-derived per request.
        // Two outputs, one sheet: the PDF for filing, the HTML view for the browser's
        // own print dialogue. Both render the same shared body partial.
        Route::get('/property-record/{type}/{id}/pdf', [PropertyRecordExportController::class, 'pdf'])
            ->whereIn('type', ['discovery', 'valuation'])
            ->name('api.property-record.pdf');
        Route::get('/property-record/{type}/{id}/print', [PropertyRecordExportController::class, 'print'])
            ->whereIn('type', ['discovery', 'valuation'])
            ->name('api.property-record.print');

        // Evidence photos (GPS + camera evidence)
        Route::get('/evidence/photos', [EvidenceController::class, 'index']);
        Route::post('/evidence/photos', [EvidenceController::class, 'store']);
        Route::get('/evidence/photos/{photo}', [EvidenceController::class, 'show']);
        Route::get('/evidence/photos/{photo}/download', [EvidenceController::class, 'download']);

        // M&E + appeals
        Route::get('/me/queries', [MEController::class, 'queries']);
        Route::post('/me/queries', [MEController::class, 'createQuery']);
        Route::post('/me/queries/{query}/respond', [MEController::class, 'respondQuery']);
        Route::post('/me/queries/{query}/close', [MEController::class, 'closeQuery']);

        // M&E operational powers
        Route::get('/me/review-board', [MEController::class, 'reviewBoard']);
        Route::post('/me/tasks/{task}/assign-walkin', [MEController::class, 'assignWalkIn']);
        Route::post('/me/tasks/{task}/reassign', [MEController::class, 'reassignTask']);
        Route::post('/me/tasks/{task}/revise', [MEController::class, 'reviseTask']);
        Route::get('/me/flags', [MEController::class, 'flags']);
        Route::post('/me/flags', [MEController::class, 'flag']);
        Route::patch('/me/flags/{flag}/resolve', [MEController::class, 'resolveFlag']);

        Route::get('/appeals', [MEController::class, 'appeals']);
        Route::post('/appeals', [MEController::class, 'createAppeal']);
        Route::post('/appeals/{appeal}/decide', [MEController::class, 'decideAppeal']);

        // Reports
        Route::get('/reports/bills', [ReportsController::class, 'bills']);
        Route::get('/reports/collections', [ReportsController::class, 'collections']);
        Route::get('/reports/enforcement', [ReportsController::class, 'enforcement']);
        Route::get('/reports/valuations', [ReportsController::class, 'valuations']);
        Route::get('/reports/discoveries', [ReportsController::class, 'discoveries']);
        Route::get('/reports/payment-queue', [ReportsController::class, 'paymentQueue']);
        Route::get('/reports/my/tasks', [ReportsController::class, 'myTasks']);
        Route::get('/reports/my/performance', [ReportsController::class, 'myPerformance']);
        Route::get('/reports/management/performance', [ReportsController::class, 'managementPerformance']);
        Route::get('/reports/my/tasks/export', [ReportsController::class, 'exportMyTasks']);
        Route::get('/reports/my/performance/export', [ReportsController::class, 'exportMyPerformance']);
        Route::get('/reports/management/performance/export', [ReportsController::class, 'exportManagementPerformance']);
        Route::get('/reports/{kind}/export', [ReportsController::class, 'export']);

        // Audit trail (hash-chained, immutable)
        Route::get('/audit-logs', [AuditController::class, 'index']);
        Route::get('/audit-logs/export', [AuditController::class, 'export']);
    });
});
