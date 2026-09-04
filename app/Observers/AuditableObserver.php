<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);

        if (empty($changes)) {
            return;
        }

        $old = [];
        foreach ($changes as $key => $new) {
            $old[$key] = $model->getOriginal($key);
        }

        $this->log($model, 'updated', $old, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', $model->getOriginal(), []);
    }

    private function log(Model $model, string $action, array $old, array $new): void
    {
        $previousHash = AuditLog::query()->orderByDesc('id')->value('hash');

        // Arrays stored in JSON columns; the chain payload canonicalizes them
        // with a deterministic json_encode so hashes reproduce exactly.
        $entry = AuditLog::create([
            'actor_id' => auth()?->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => empty($old) ? null : $old,
            'new_values' => empty($new) ? null : $new,
            'ip_address' => request()?->ip(),
            'previous_hash' => $previousHash,
        ]);

        $entry->update(['hash' => $entry->computeHash()]);
    }
}