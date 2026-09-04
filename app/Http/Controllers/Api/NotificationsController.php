<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\EnforcementVisit;
use App\Models\MeQuery;
use App\Models\Notification;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    /** Reference prefix => [model, column]. Used to resolve the linked record from a message. */
    private const REF_MODELS = [
        'MEQ-' => [MeQuery::class, 'query_reference'],
        'APP-' => [Appeal::class, 'appeal_reference'],
        'TASK-' => [Task::class, 'task_reference'],
        'VAL-' => [Valuation::class, 'valuation_reference'],
        'ND-' => [PropertyDiscovery::class, 'discovery_reference'],
        'VIS-' => [EnforcementVisit::class, 'visit_reference'],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('notifications.view'), 403, 'Missing permission: notifications.view');

        $rows = $user->notifications()
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 20));

        $rows->getCollection()->transform(fn (Notification $n) => [
            'id' => $n->id,
            'title' => $n->title,
            'message' => $n->message,
            'type' => $n->type,
            'action_url' => $n->action_url,
            'read_at' => $n->read_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
            'detail' => $this->presentDetail($user, $n),
        ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['unread_count' => $user->notifications()->unread()->count()],
        ]);
    }

    public function unreadCount(Request $request)
    {
        abort_unless($request->user()->canPermission('notifications.view'), 403, 'Missing permission: notifications.view');

        return response()->json([
            'data' => ['unread_count' => $request->user()->notifications()->unread()->count()],
        ]);
    }

    public function markRead(Request $request, Notification $notification)
    {
        abort_unless($request->user()->canPermission('notifications.view'), 403, 'Missing permission: notifications.view');

        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Notification does not belong to you.'], 403);
        }

        $notification->markRead();

        return response()->json(['data' => ['id' => $notification->id], 'message' => 'Marked read.']);
    }

    public function readAll(Request $request)
    {
        abort_unless($request->user()->canPermission('notifications.view'), 403, 'Missing permission: notifications.view');

        $request->user()->notifications()->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked read.']);
    }

    public function broadcast(Request $request)
    {
        $user = $request->user();

        if (! $user->isSystemAdministrator() && ! $user->canPermission('notifications.broadcast')) {
            return response()->json(['message' => 'You are not permitted to broadcast.'], 403);
        }

        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'title' => 'nullable|string|max:255',
            'role_id' => ['nullable', 'integer'],
        ]);

        $query = User::query()->where('is_active', true);

        if ($data['role_id'] ?? null) {
            $query->where('role_id', $data['role_id']);
        }

        $recipients = $query->pluck('id');

        foreach ($recipients as $userId) {
            Notification::create([
                'user_id' => $userId,
                'title' => $data['title'] ?? 'Announcement',
                'message' => $data['message'],
                'type' => 'broadcast',
            ]);
        }

        return response()->json(['message' => 'Broadcast sent to '.$recipients->count().' active users.']);
    }

    /* ------------------------------------------------------------------ */
    /*  Detail + action resolution                                         */
    /* ------------------------------------------------------------------ */

    private function presentDetail(User $user, Notification $n): array
    {
        $related = $this->resolveRelated($n);

        return match (true) {
            $related instanceof MeQuery => $this->queryDetail($user, $related),
            $related instanceof Appeal => $this->appealDetail($user, $related),
            $related instanceof Task => $this->taskDetail($related),
            $related instanceof Valuation => $this->valuationDetail($related),
            $related instanceof PropertyDiscovery => $this->discoveryDetail($related),
            $related instanceof EnforcementVisit => $this->visitDetail($related),
            default => ['kind' => 'info', 'link' => '', 'ref' => null, 'fields' => [], 'actions' => []],
        };
    }

    private function resolveRelated(Notification $n): mixed
    {
        if (! preg_match('/\b(MEQ|APP|TASK|VAL|ND|VIS)-\d{4}-\d{4,6}\b/', (string) $n->message, $m)) {
            return null;
        }

        [$model, $column] = self::REF_MODELS[$m[1].'-'] ?? [null, null];

        return $model ? $model::where($column, $m[0])->first() : null;
    }

    private function queryDetail(User $user, MeQuery $query): array
    {
        $query->loadMissing('assignee:id,full_name');

        $actions = [];
        if ($user->canPermission('me.close') && in_array($query->status, ['Open', 'Answered'], true)) {
            $actions[] = ['id' => 'close', 'label' => 'Close query', 'endpoint' => "/me/queries/{$query->id}/close", 'method' => 'POST', 'kind' => 'confirm'];
        }
        if ($user->canPermission('me.respond') && $query->status === 'Open') {
            $actions[] = ['id' => 'respond', 'label' => 'Respond', 'endpoint' => "/me/queries/{$query->id}/respond", 'method' => 'POST', 'kind' => 'text', 'field' => 'response', 'fieldLabel' => 'Your response'];
        }

        $fields = [
            ['l' => 'Reference', 'v' => $query->query_reference],
            ['l' => 'Title', 'v' => $query->title],
            ['l' => 'Priority', 'v' => $query->priority ?? 'Normal'],
            ['l' => 'Status', 'v' => $query->status],
            ['l' => 'Assigned to', 'v' => $query->assignee?->full_name ?? '—'],
            ['l' => 'Description', 'v' => $query->description],
        ];
        if ($query->response) {
            $fields[] = ['l' => 'Response', 'v' => $query->response];
        }
        $fields[] = ['l' => 'Asked', 'v' => $query->created_at?->format('Y-m-d H:i') ?? '—'];

        return ['kind' => 'query', 'link' => '', 'ref' => $query->query_reference, 'fields' => $fields, 'actions' => $actions];
    }

    private function appealDetail(User $user, Appeal $appeal): array
    {
        $actions = [];
        $decidable = ! in_array($appeal->status, ['Upheld', 'Adjusted', 'Dismissed', 'Withdrawn'], true);
        if ($decidable && $user->hasAnyPermission(['me.view', 'valuation.approve'])) {
            $actions[] = [
                'id' => 'decide',
                'label' => 'Decide appeal',
                'endpoint' => "/appeals/{$appeal->id}/decide",
                'method' => 'POST',
                'kind' => 'appeal',
                'options' => [
                    ['v' => 'upheld', 'l' => 'Upheld (approve)'],
                    ['v' => 'adjusted', 'l' => 'Adjusted (re-assess)'],
                    ['v' => 'dismissed', 'l' => 'Dismissed (reject)'],
                ],
            ];
        }

        return [
            'kind' => 'appeal',
            'link' => '',
            'ref' => $appeal->appeal_reference,
            'fields' => [
                ['l' => 'Reference', 'v' => $appeal->appeal_reference],
                ['l' => 'Document #', 'v' => $appeal->document_number ?? '—'],
                ['l' => 'Property ID', 'v' => $appeal->property_id ?? '—'],
                ['l' => 'Taxpayer', 'v' => $appeal->taxpayer_name ?? '—'],
                ['l' => 'Reason', 'v' => $appeal->reason],
                ['l' => 'Description', 'v' => $appeal->description],
                ['l' => 'Status', 'v' => $appeal->status],
                ['l' => 'Decision notes', 'v' => $appeal->decision_notes ?? '—'],
            ],
            'actions' => $actions,
        ];
    }

    private function taskDetail(Task $task): array
    {
        $task->loadMissing('assignedTo:id,full_name');

        return [
            'kind' => 'task',
            'link' => '/tasks',
            'ref' => $task->task_reference,
            'fields' => [
                ['l' => 'Reference', 'v' => $task->task_reference],
                ['l' => 'Type', 'v' => $task->task_type],
                ['l' => 'Section', 'v' => $task->section ?? '—'],
                ['l' => 'Status', 'v' => $task->status],
                ['l' => 'Priority', 'v' => $task->priority ?? '—'],
                ['l' => 'Due', 'v' => $task->due_date?->toDateString() ?? '—'],
                ['l' => 'Assignee', 'v' => $task->assignedTo?->full_name ?? '—'],
            ],
            'actions' => [],
        ];
    }

    private function valuationDetail(Valuation $valuation): array
    {
        return [
            'kind' => 'valuation',
            'link' => '/valuations',
            'ref' => $valuation->valuation_reference,
            'fields' => [
                ['l' => 'Reference', 'v' => $valuation->valuation_reference],
                ['l' => 'Owner', 'v' => $valuation->owner_name ?? '—'],
                ['l' => 'Status', 'v' => $valuation->status],
                ['l' => 'Assessed value', 'v' => number_format((float) $valuation->total_property_value, 2)],
                ['l' => 'Tax payable', 'v' => number_format((float) $valuation->total_tax_payable, 2)],
            ],
            'actions' => [],
        ];
    }

    private function discoveryDetail(PropertyDiscovery $discovery): array
    {
        return [
            'kind' => 'discovery',
            'link' => '/discoveries',
            'ref' => $discovery->discovery_reference,
            'fields' => [
                ['l' => 'Reference', 'v' => $discovery->discovery_reference],
                ['l' => 'Owner', 'v' => $discovery->owner_name ?? '—'],
                ['l' => 'Status', 'v' => $discovery->status],
                ['l' => 'Path', 'v' => $discovery->decision_path ?? 'not set'],
            ],
            'actions' => [],
        ];
    }

    private function visitDetail(EnforcementVisit $visit): array
    {
        return [
            'kind' => 'visit',
            'link' => '/enforcement',
            'ref' => $visit->visit_reference,
            'fields' => [
                ['l' => 'Reference', 'v' => $visit->visit_reference],
                ['l' => 'Document #', 'v' => $visit->document_number ?? '—'],
                ['l' => 'Visit date', 'v' => $visit->visit_date?->toDateString() ?? '—'],
                ['l' => 'Visit status', 'v' => $visit->visit_status ?? 'Scheduled'],
                ['l' => 'Delivery status', 'v' => $visit->delivery_status ?? '—'],
            ],
            'actions' => [],
        ];
    }
}