<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncTransaction;
use Illuminate\Http\Request;

/**
 * Offline-first sync ledger — lets the mobile Sync tab reconcile which of its
 * transactions the central server has already accepted (and which were replays).
 */
class SyncController extends Controller
{
    private const REQUIRED = [
        'tasks.view_own',
        'enforcement.record_visit',
        'discovery.create',
        'valuation.create',
        'payments.claim',
        'enforcement.upload_evidence',
        'bills.amend_document',
        'bills.amend_penalty_interest',
    ];

    public function transactions(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(self::REQUIRED), 403, 'Missing permission.');

        $rows = SyncTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(min((int) $request->query('per_page', 100), 300))
            ->get()
            ->map(fn (SyncTransaction $t) => [
                'id' => $t->id,
                'local_transaction_id' => $t->local_transaction_id,
                'entity_type' => $t->entity_type,
                'entity_id' => $t->entity_id,
                'status' => $t->status,
                'synced_at' => $t->synced_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'counts' => [
                    'visit' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'visit')->count(),
                    'discovery' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'discovery')->count(),
                    'valuation' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'valuation')->count(),
                    'receipt' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'receipt')->count(),
                    'action' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'action')->count(),
                    'engagement' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'engagement')->count(),
                    'bill_amendment' => SyncTransaction::where('user_id', $user->id)->where('entity_type', 'bill_amendment')->count(),
                ],
                'transactions' => $rows,
            ],
        ]);
    }
}
