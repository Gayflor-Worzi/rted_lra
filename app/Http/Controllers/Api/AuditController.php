<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Audit trail — list and export the immutable hash-chained audit records.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('audit.view'), 403, 'Missing permission: audit.view');

        $query = AuditLog::query()->with('actor:id,full_name');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', like_term($action));
        }

        if ($actorId = $request->query('actor')) {
            $query->where('actor_id', (int) $actorId);
        }

        if ($request->query('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 25))->withQueryString();
        $rows->getCollection()->transform(fn (AuditLog $a) => $this->present($a));

        return response()->json(['data' => $rows]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('audit.export'), 403, 'Missing permission: audit.export');

        $query = AuditLog::query()->with('actor:id,full_name');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', like_term($action));
        }

        if ($request->query('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        $headers = ['ID', 'Action', 'Actor', 'Auditable', 'Auditable ID', 'IP Address', 'Hash', 'Details', 'Created At'];
        $rows = $query->orderByDesc('id')->limit(5000)->get()->map(fn (AuditLog $a) => [
            'ID' => $a->id,
            'Action' => $a->action,
            'Actor' => $a->relationLoaded('actor') ? ($a->actor?->full_name ?? 'System') : 'System',
            'Auditable' => class_basename((string) $a->auditable_type),
            'Auditable ID' => $a->auditable_id,
            'IP Address' => $a->ip_address ?? '',
            'Hash' => substr((string) $a->hash, 0, 16),
            'Details' => json_encode($a->new_values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'Created At' => $a->created_at?->toDateTimeString(),
        ])->toArray();

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Audit Trail — All records']);
        fputcsv($out, ['Generated '.now('Africa/Monrovia')->format('Y-m-d H:i').' by '.$user->full_name]);
        fputcsv($out, []);
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        rewind($out);

        return response(stream_get_contents($out))
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="retd_audit_'.date('Ymd_His').'.csv"');
    }

    private function present(AuditLog $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'actor' => $a->relationLoaded('actor') ? ($a->actor?->full_name ?? 'System') : null,
            'actor_id' => $a->actor_id,
            'auditable_type' => class_basename((string) $a->auditable_type),
            'auditable_id' => $a->auditable_id,
            'old_values' => $a->old_values,
            'new_values' => $a->new_values,
            'ip_address' => $a->ip_address,
            'hash' => substr((string) $a->hash, 0, 16),
            'created_at' => $a->created_at?->toISOString(),
        ];
    }
}