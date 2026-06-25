<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Events;

/**
 * Dispatched after a number has been allocated. For gap-free types this fires
 * inside the caller's transaction, so a later rollback also discards anything
 * a listener did within that transaction.
 */
final class NumberAllocated
{
    public function __construct(
        public readonly string $scope,
        public readonly string $type,
        public readonly string $periodKey,
        public readonly int $sequence,
        public readonly string $number,
    ) {}
}
