<?php

namespace App\Models\Concerns;

use App\Support\BusinessClock;
use Illuminate\Support\Str;
use LogicException;

/**
 * Assigns internal references of the form PREFIX-YYYY-##### for all RETD
 * work records (tasks, visits, valuations, M&E queries, appeals, photos,
 * discoveries).
 *
 * G0.4 — references must be unique-by-construction rather than by a
 * pre-insert "max(id)+1" read (which raced under concurrent creates: two
 * requests computing the same number either exploded on the unique
 * constraint or silently wrote duplicate VIS references).
 *
 * The canonical number is the row's own auto-increment id, which is unique
 * at the database level — so two concurrent inserts can never be attributed
 * the same reference. A collision-free placeholder (PREFIX-YYYY-XXXXXX, 8
 * upper-case chars/digits) satisfies the NOT NULL unique columns at insert;
 * the created hook immediately replaces it with the canonical reference.
 */
trait GeneratesInternalReference
{
    /** Series prefix, e.g. 'TASK'. */
    abstract public static function referencePrefix(): string;

    /** Column holding the reference, e.g. 'task_reference'. */
    abstract public static function referenceField(): string;

    protected static function bootGeneratesInternalReference(): void
    {
        static::creating(function ($model) {
            $field = static::referenceField();
            if (empty($model->{$field})) {
                $model->{$field} = static::placeholderReference();
            }
        });

        static::created(function ($model) {
            $field = static::referenceField();
            $value = (string) $model->{$field};

            if (preg_match('/'.static::referencePrefix().'-\d{4}-[A-Z0-9]{8}$/', $value)) {
                $model->{$field} = static::canonicalReference($model->getKey());
                $model->saveQuietly();
            }
        });

        // Once assigned, a reference is permanent. It is the number the whole
        // workflow is tracked by: task and notification labels, audit rows, exports,
        // reports and the reconciliation command all key off it, so a record whose
        // reference changed would appear under two different numbers and no longer
        // reconcile with the history already recorded against the first one. The
        // canonical form is derived from the row id, which can never be edited, so
        // the value is also unique by construction rather than by a "max(id)+1" read.
        static::updating(function ($model) {
            $field = static::referenceField();

            if (! $model->isDirty($field)) {
                return;
            }

            throw new LogicException(sprintf(
                'The %s of a %s is permanent and cannot be changed once assigned (attempted "%s" -> "%s").',
                $field,
                class_basename($model),
                (string) $model->getOriginal($field),
                (string) $model->getAttribute($field)
            ));
        });
    }

    public static function placeholderReference(): string
    {
        return sprintf('%s-%s-%s', static::referencePrefix(), BusinessClock::now()->format('Y'), Str::upper(Str::random(8)));
    }

    public static function canonicalReference(int $id): string
    {
        return sprintf('%s-%s-%05d', static::referencePrefix(), BusinessClock::now()->format('Y'), $id);
    }
}
