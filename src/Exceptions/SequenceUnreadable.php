<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Exceptions;

/**
 * Thrown when the sequence row cannot be read back after it was ensured to
 * exist. Continuing from zero would re-issue a number that has already been
 * used, so allocation stops instead and the transaction is rolled back.
 */
final class SequenceUnreadable extends NumberingException
{
    public function __construct(
        public readonly string $scope,
        public readonly string $type,
        public readonly string $periodKey,
    ) {
        parent::__construct(sprintf(
            'The sequence row for scope [%s], type [%s], period [%s] could not be read back '
            .'after being created. Check that the numbering table has no columns the package '
            .'does not populate; continuing would re-issue a number that is already in use.',
            $scope,
            $type,
            $periodKey,
        ));
    }
}
