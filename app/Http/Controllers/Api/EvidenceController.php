<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Photo evidence capture — GPS + camera from field (mobile) or uploaded files
 * (web). Photos are stored under storage/app/evidence; references link the shot
 * to a bill, task, visit or valuation. Property ID is always the LITAS value.
 */
class EvidenceController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

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

        $data = $request->validate([
            'photo_type' => ['required', Rule::in(EvidencePhoto::TYPES)],
            'file' => 'nullable|image|max:10240',
            'data_uri' => 'nullable|string',
            'path' => 'nullable|string|max:255',
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
            $name = 'evidence/'.Str::lower(Str::random(1)).'/'.date('Ymd_His').'_'.Str::random(16).'.'.Str::after($mime, '/');
            Storage::disk('local')->put($name, $bytes);
            $filePath = $name;
            $size = strlen($bytes);
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

        // Keys must match the migration columns exactly.
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

    public function show(EvidencePhoto $photo)
    {
        $user = request()->user();
        abort_unless($user->hasAnyPermission([
            'enforcement.upload_evidence', 'payments.verify', 'payments.claim',
            'valuation.create', 'valuation.review', 'valuation.approve', 'valuation.view_history',
            'discovery.upload_photo',
        ]), 403, 'Missing permission to view evidence.');

        $photo->load('officer:id,full_name');

        return response()->json(['data' => $photo]);
    }

    public function download(EvidencePhoto $photo)
    {
        $user = request()->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'payments.verify', 'valuation.review', 'valuation.approve']), 403, 'Missing permission.');

        abort_unless($photo->file_path && Storage::disk('local')->exists($photo->file_path), 404, 'Photo not found.');

        return Storage::disk('local')->download($photo->file_path, $photo->photo_reference.'.jpg');
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
