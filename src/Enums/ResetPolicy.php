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
     * Normalise a loose config value (string or enum) into a ResetPolicy.
     */
    public static function fromConfig(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
