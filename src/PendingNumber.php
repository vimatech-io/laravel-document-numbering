<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering;

/**
 * Fluent, scope-bound entry point returned by Numbering::for(). It defers to
 * the manager so the public API reads as Numbering::for($scope, $type)->next().
 */
final class PendingNumber
{
    public function __construct(
        private readonly NumberingManager $manager,
        private readonly string $scope,
        private readonly string $type,
    ) {}

    /**
     * Allocate and consume the next number for this scope/type.
     */
    public function next(): string
    {
        return $this->manager->next($this->scope, $this->type);
    }

    /**
     * Return the number that next() would produce, without consuming it.
     * The value is advisory: under concurrency another caller may take it
     * before you do.
     */
    public function peek(): string
    {
        return $this->manager->peek($this->scope, $this->type);
    }

    /**
     * Whether this sequence has ever consumed a number, in any period — the
     * question peek() cannot answer, since it only sees the current period.
     */
    public function hasEverAllocated(): bool
    {
        return $this->manager->hasEverAllocated($this->scope, $this->type);
    }
}
