<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A receipt claim conflicts with an existing claim (duplicate submission,
 * receipt already used for another property, or a competing claim winning a
 * race). Carries a machine-readable context snapshot for the audit trail.
 */
class ReceiptConflictException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $reason, array $context = []): self
    {
        $e = new self($reason, 422);

        $e->context = $context;

        return $e;
    }
}
