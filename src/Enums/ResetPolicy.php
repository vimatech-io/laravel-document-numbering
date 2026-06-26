<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Enums;

use DateTimeInterface;

/**
 * Determines when a sequence restarts at 1 and how the period key is derived.
 */
enum ResetPolicy: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';

    /**
     * Build a stable period key for the given moment. Two allocations share a
     * counter if and only if they produce the same (scope, type, period key).
     */
    public function periodKey(DateTimeInterface $at): string
    {
        return match ($this) {
            self::Never => 'all',
            self::Yearly => $at->format('Y'),
            self::Monthly => $at->format('Y-m'),
        };
    }

    /**
     * Normalise a loose config value into a ResetPolicy. Accepts an enum case
     * or its string value; anything else (e.g. a missing key) falls back to
     * Never. An unknown string is rejected loudly so misconfiguration surfaces.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::from($value) : self::Never;
    }
}
