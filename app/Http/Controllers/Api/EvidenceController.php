<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementVisit;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\AuditService;
use App\Services\BillScopeService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Photo evidence capture — GPS + camera from field (mobile) or uploaded files
 * (web). Photos are stored under storage/app/evidence; references link the shot
 * to a bill, task, visit or valuation. Property ID is always the source-system value.
 */
class EvidenceController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'payments.claim', 'valuation.create', 'valuation.view_history']), 403, 'Missing permission.');

        $query = EvidencePhoto::query()->with('officer:id,full_name')->orderByDesc('id');

        if ($billId = $request->query('bill_id')) {
            $query->where('bill_id', $billId);
        }
        if ($taskId = $request->query('task_id')) {
            $query->where('task_id', $taskId);
        }
        if ($visitId = $request->query('visit_id')) {
            $query->where('visit_id', $visitId);
        }
        if ($valuationId = $request->query('valuation_id')) {
            $query->where('valuation_id', $valuationId);
        }
        if ($discoveryId = $request->query('discovery_id')) {
            $query->where('discovery_id', $discoveryId);
        }
        if ($q = $request->query('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('photo_reference', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q));
            });
        }

        // Always apply data scope — no user can enumerate all evidence photos
        // regardless of their permissions. Officers see only their own photos +
        // bills they're authorized for; managers see their section/team.
        // System/division-wide evidence managers may browse the full store.
        if (! $this->isEvidenceManager($user)) {
            $query->where(function ($b) use ($user) {
                $b->where('officer_id', $user->id);
                if ($ids = $this->visibleBillIds($user)) {
                    $b->orWhereIn('bill_id', $ids);
                }
            });
        }

        $rows = $query->paginate($request->query('per_page', 20))->withQueryString();

        return response()->json(['data' => $rows]);
    }

    /**
     * Upload one photo. Accepts a multipart `file`, a base64 `data_uri`, or a
     * device `path` reference (mobile may send a phone URI that the server cannot
     * access — stored as evidence of capture with the GPS stamp).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'discovery.upload_photo', 'valuation.edit', 'valuation.create']), 403, 'Missing permission.');

        // Idempotency guard — replays of an already-committed offline transaction
        // (device retry / auto-resubmission) are short-circuited, never duplicated.
        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'photo_type' => ['required', Rule::in(EvidencePhoto::TYPES)],
            'file' => 'nullable|image|max:10240',
            'data_uri' => 'nullable|string',
            'path' => 'nullable|string|max:255',
            'local_transaction_id' => 'nullable|string|max:80',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'visit_id' => 'nullable|integer|exists:enforcement_visits,id',
            'valuation_id' => 'nullable|integer|exists:valuations,id',
            'discovery_id' => 'nullable|integer|exists:property_discoveries,id',
            'property_id' => 'nullable|string|max:60',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_coordinate' => 'nullable|string|max:100',
            'captured_at' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
        ]);

        abort_unless($data['bill_id'] ?? $data['task_id'] ?? $data['visit_id'] ?? $data['valuation_id'] ?? $data['discovery_id'] ?? null, 422, 'A bill, task, visit, valuation or discovery must be attached.');

        if (! $request->hasFile('file') && empty($data['data_uri']) && empty($data['path'])) {
            abort(422, 'A file, data_uri or path is required.');
        }

        $filePath = null;
        $originalName = null;
        $mime = null;
        $size = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $filePath = $file->store('evidence', 'local');
        } elseif (! empty($data['data_uri'])) {
            [$mime, $bytes] = $this->decodeDataUri($data['data_uri']);
            $decodedSize = strlen($bytes);
            abort_if($decodedSize > 10240 * 1024, 422, 'Photo exceeds the 10 MB limit.');
            $name = 'evidence/'.Str::lower(Str::random(1)).'/'.date('Ymd_His').'_'.Str::random(16).'.'.Str::after($mime, '/');
            Storage::disk('local')->put($name, $bytes);
            $filePath = $name;
            $size = $decodedSize;
        } else {
            $filePath = $data['path']; // device path — reference only
        }

        $gps = $data['gps_coordinate'] ?? (isset($data['gps_lat'], $data['gps_lng'])
            ? trim($data['gps_lat'].','.$data['gps_lng'])
            : null);

        $propertyId = $data['property_id'] ?? null;
        if (! $propertyId && ! empty($data['task_id'])) {
            $task = Task::find($data['task_id']);
            $propertyId = $task?->reference_type === 'property_bill'
                ? PropertyBill::find($task->reference_id)?->property_id
                : null;
        }

        // Record-scope gate: uploads must be tied to records the officer owns.
        if (! $this->isEvidenceManager($user)) {
            $owned = (isset($data['task_id']) && Task::whereKey($data['task_id'])->where('assigned_to', $user->id)->exists())
                || (isset($data['visit_id']) && EnforcementVisit::whereKey($data['visit_id'])->where('officer_id', $user->id)->exists())
                || (isset($data['valuation_id']) && Valuation::whereKey($data['valuation_id'])->where('valuation_officer_id', $user->id)->exists())
                || (isset($data['discovery_id']) && PropertyDiscovery::whereKey($data['discovery_id'])->where('discovered_by', $user->id)->exists());
            if (! $owned && ! empty($data['bill_id'])) {
                $owned = in_array((int) $data['bill_id'], $this->visibleBillIds($user) ?? [], true);
            }
            abort_unless($owned, 403, 'You can only attach evidence to records you own.');
        }

        // Keys must match the migration columns exactly.
        $photo = DB::transaction(function () use ($data, $user, $propertyId, $filePath, $originalName, $mime, $size, $gps, $syncId) {
            $photo = EvidencePhoto::create([
                'photo_type' => $data['photo_type'],
                'bill_id' => $data['bill_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'valuation_id' => $data['valuation_id'] ?? null,
                'discovery_id' => $data['discovery_id'] ?? null,
                'property_id' => $propertyId,
                'officer_id' => $user->id,
                'file_path' => $filePath,
                'original_name' => $originalName,
                'mime' => $mime,
                'size_bytes' => $size,
                'gps_coordinate' => $gps,
                'captured_at' => $data['captured_at'] ?? now(),
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->audit->record($photo, 'evidence.photo_uploaded', $user->id, [], [
                'photo_reference' => $photo->photo_reference,
                'photo_type' => $photo->photo_type,
            ]);

            if ($syncId) {
                $this->sync->record($user->id, $syncId, 'evidence', $photo->id, [
                    'id' => $photo->id,
                    'photo_reference' => $photo->photo_reference,
                ]);
            }

            return $photo;
        });

        return response()->json([
            'data' => [
                'id' => $photo->id,
                'photo_reference' => $photo->photo_reference,
                'photo_type' => $photo->photo_type,
                'file_path' => $photo->file_path,
                'gps_coordinate' => $photo->gps_coordinate,
                'captured_at' => $photo->captured_at,
            ],
            'message' => 'Evidence photo captured.',
        ], 201);
    }

    public function show(Request $request, EvidencePhoto $photo)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission([
            'enforcement.upload_evidence', 'payments.verify', 'payments.claim',
            'valuation.create', 'valuation.review', 'valuation.approve', 'valuation.view_history',
            'discovery.upload_photo',
        ]), 403, 'Missing permission to view evidence.');
        abort_unless($this->canViewPhoto($photo, $user), 403, 'Photo is outside your scope.');

        $photo->load('officer:id,full_name');

        return response()->json(['data' => $photo]);
    }

    public function download(Request $request, EvidencePhoto $photo)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'payments.verify', 'valuation.review', 'valuation.approve']), 403, 'Missing permission.');
        abort_unless($this->canViewPhoto($photo, $user), 403, 'Photo is outside your scope.');

        abort_unless(
            $photo->file_path
            && str_starts_with($photo->file_path, 'evidence/')
            && ! str_contains($photo->file_path, '..')
            && Storage::disk('local')->exists($photo->file_path),
            404,
            'Photo not found.',
        );

        return Storage::disk('local')->download($photo->file_path, $photo->photo_reference.'.jpg');
    }

    /** Record-level evidence access: owner officer, their supervisor, or authorized scope. */
    private function canViewPhoto(EvidencePhoto $photo, User $user): bool
    {
        // The recording officer always owns their photo.
        if ($photo->officer_id === $user->id) {
            return true;
        }

        // A supervisor may view photos from their team.
        if ($user->scopeLevel() === 'team' && $photo->officer_id) {
            return User::where('id', $photo->officer_id)->where('supervisor_id', $user->id)->exists();
        }

        // A user with a linked bill they're authorized for may view that photo.
        $authorizedBills = PropertyBill::where(function ($q) use ($user) {
            $q->where('account_staff_id', $user->id);
            if ($user->scopeLevel() === 'team') {
                $q->orWhereIn('assigned_enforcement_officer_id', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
            } elseif ($user->scopeLevel() === 'section') {
                $q->orWhereIn('assigned_enforcement_officer_id', User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all());
            }
        })->pluck('id')->values()->all();

        if ($photo->bill_id && in_array($photo->bill_id, $authorizedBills, true)) {
            return true;
        }

        // Evidence managers (division/system) see everything.
        if ($this->isEvidenceManager($user)) {
            return true;
        }

        return false;
    }

    /** Evidence managers (division/system scope) cross-check any record. */
    private function isEvidenceManager(User $user): bool
    {
        return in_array($user->scopeLevel(), ['division', 'system'], true);
    }

    /** @see BillScopeService::visibleBillIds() */
    private function visibleBillIds(User $user): ?array
    {
        return BillScopeService::visibleBillIds($user);
    }

    private function decodeDataUri(string $dataUri): array
    {
        if (str_contains($dataUri, ';base64,')) {
            [, $b64] = explode(';base64,', $dataUri, 2);
            $mime = Str::before(Str::after($dataUri, 'data:'), ';');
        } elseif (str_starts_with($dataUri, 'base64,')) {
            $b64 = Str::after($dataUri, 'base64,');
            $mime = 'image/jpeg';
        } else {
            $b64 = $dataUri;
            $mime = 'image/jpeg';
        }

        $bytes = base64_decode(trim($b64), true);
        if ($bytes === false) {
            abort(422, 'Invalid base64 image data.');
        }

        return [$mime, $bytes];
    }
}
