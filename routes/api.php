<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\BillRegisterController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\EnforcementController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\MEController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SectionsController;
use App\Http\Controllers\Api\TargetsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\ValuationsController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RETD Tasks Management API  (LRA — Domestic Tax Department — RETD)
|--------------------------------------------------------------------------
| LITAS boundary: Document # and Property ID originate in LITAS. This
| application logs, stores, displays and searches them — it never generates
| them, never generates tax bills, and never claims live LITAS integration.
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

        // Bills — RETD Bill Register (LITAS identifiers)
        Route::get('/property-bills', [BillRegisterController::class, 'index']);
        Route::post('/property-bills', [BillRegisterController::class, 'store']);
        Route::get('/property-bills/{property_bill}', [BillRegisterController::class, 'show']);
        Route::put('/property-bills/{property_bill}', [BillRegisterController::class, 'update']);
        Route::post('/property-bills/{property_bill}/assign', [BillRegisterController::class, 'assign']);

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
        Route::get('/dashboard/division', [DashboardController::class, 'division']);
        Route::get('/dashboard/analytics', [AnalyticsController::class, 'summary']);
        Route::get('/dashboard/analytics/export', [AnalyticsController::class, 'export']);
        Route::get('/dashboard/drill', [DashboardController::class, 'drill']);
        Route::get('/dashboard/integrity', [DashboardController::class, 'integrity']);

        // New Property Discovery (first-class workflow)
        Route::get('/discoveries', [DiscoveryController::class, 'index']);
        Route::post('/discoveries', [DiscoveryController::class, 'store']);
        Route::get('/discoveries/stats', [DiscoveryController::class, 'stats']);
Route::get('/property-profile', [DiscoveryController::class, 'profile']);
        Route::get('/discoveries/{discovery}', [DiscoveryController::class, 'show']);
        Route::put('/discoveries/{discovery}', [DiscoveryController::class, 'update']);
        Route::post('/discoveries/{discovery}/submit', [DiscoveryController::class, 'submit']);
        Route::post('/discoveries/{discovery}/resubmit', [DiscoveryController::class, 'resubmit']);
        Route::post('/discoveries/{discovery}/review', [DiscoveryController::class, 'review']);
        Route::post('/discoveries/{discovery}/classify', [DiscoveryController::class, 'classify']);
        Route::post('/discoveries/{discovery}/route-to-account', [DiscoveryController::class, 'routeToAccount']);
        Route::post('/discoveries/{discovery}/account-processing', [DiscoveryController::class, 'accountProcessing']);
        Route::post('/discoveries/{discovery}/complete', [DiscoveryController::class, 'complete']);
        Route::post('/discoveries/{discovery}/route-to-valuation', [DiscoveryController::class, 'routeToValuation']);
        Route::post('/discoveries/{discovery}/approve', [DiscoveryController::class, 'approve']);
        Route::post('/discoveries/{discovery}/reject', [DiscoveryController::class, 'reject']);
        Route::post('/discoveries/{discovery}/reopen', [DiscoveryController::class, 'reopen']);

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

        // Payments / verification
        Route::get('/payments/queue', [PaymentsController::class, 'queue']);
        Route::get('/payments/history', [PaymentsController::class, 'history']);
        Route::get('/payments/verifications/{verification}/receipt', [PaymentsController::class, 'receipt']);
        Route::post('/payments/verifications/{verification}/confirm', [PaymentsController::class, 'confirm']);
        Route::post('/payments/verifications/{verification}/reject', [PaymentsController::class, 'reject']);

        // Valuations
        Route::get('/valuations', [ValuationsController::class, 'index']);
        Route::post('/valuations', [ValuationsController::class, 'store']);
        Route::get('/valuations/{valuation}', [ValuationsController::class, 'show']);
        Route::put('/valuations/{valuation}', [ValuationsController::class, 'update']);
        Route::post('/valuations/{valuation}/submit', [ValuationsController::class, 'submit']);
        Route::post('/valuations/{valuation}/review', [ValuationsController::class, 'review']);
        Route::post('/valuations/{valuation}/assign', [ValuationsController::class, 'assign']);
        Route::post('/valuations/{valuation}/decide', [ValuationsController::class, 'decide']);
        Route::post('/valuations/{valuation}/processing', [ValuationsController::class, 'processing']);
        Route::post('/valuations/{valuation}/descriptions', [ValuationsController::class, 'descriptions']);

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
        Route::get('/reports/{kind}/export', [ReportsController::class, 'export']);

        // Audit trail (hash-chained, immutable)
        Route::get('/audit-logs', [AuditController::class, 'index']);
        Route::get('/audit-logs/export', [AuditController::class, 'export']);
    });
});
