<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Exceptions;

use Throwable;

/**
 * Thrown when the sequence row cannot be locked for update, e.g. the database
 * aborted the statement after a lock-wait timeout or deadlock. The surrounding
 * transaction is rolled back, so no number is consumed.
 */
final class SequenceLocked extends NumberingException
{
    public function __construct(
        public readonly string $scope,
        public readonly string $type,
        public readonly string $periodKey,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Could not acquire a lock on the [%s/%s] sequence for period [%s].',
                $type,
                $scope,
                $periodKey,
            ),
            0,
            $previous,
        );
    }
}
