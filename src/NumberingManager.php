<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Vimatech\DocumentNumbering\Enums\ResetPolicy;
use Vimatech\DocumentNumbering\Events\NumberAllocated;
use Vimatech\DocumentNumbering\Exceptions\SequenceLocked;
use Vimatech\DocumentNumbering\Exceptions\SequenceUnreadable;
use Vimatech\DocumentNumbering\Exceptions\UnknownDocumentType;
use Vimatech\DocumentNumbering\Support\PatternCompiler;

/**
 * Allocates sequential, concurrency-safe document numbers.
 *
 * Each (scope, type, period) tuple owns one counter row. Allocation runs in a
 * transaction and locks that row with `lockForUpdate()`, so concurrent callers
 * serialise on the row rather than racing the counter. For gap-free types the
 * allocation joins the caller's transaction, so a rollback releases the number.
 *
 * The manager holds no mutable state: configuration is read from the config
 * repository on demand and the connection is resolved per call. That keeps it
 * safe to use as a long-lived singleton under FrankenPHP/Octane worker mode,
 * where it survives across requests.
 *
 * @phpstan-type TypeConfig array{pattern: string, reset: ResetPolicy, gap_free: bool}
 */
final class NumberingManager
{
    /**
     * Fallback retry count when none is configured (see numbering.lock_attempts).
     */
    private const DEFAULT_LOCK_ATTEMPTS = 5;

    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly Dispatcher $events,
        private readonly PatternCompiler $compiler,
        private readonly Repository $config,
    ) {}

    /**
     * Begin a scope-bound allocation: Numbering::for($scope, $type)->next().
     */
    public function for(string $scope, string $type): PendingNumber
    {
        return new PendingNumber($this, $scope, $type);
    }

    /**
     * Allocate and consume the next number.
     */
    public function next(string $scope, string $type): string
    {
        $config = $this->configFor($type);
        $at = $this->now();
        $periodKey = $config['reset']->periodKey($at);
        $connection = $this->connection();

        // The formatted number is captured by reference rather than returned
        // through transaction(), whose declared return type varies between
        // framework/analyser versions. This keeps next() strictly typed.
        $number = '';

        $allocate = function () use (&$number, $connection, $scope, $type, $periodKey, $config, $at): void {
            $sequence = $this->lockCurrentValue($connection, $scope, $type, $periodKey, $at) + 1;

            $this->rows($connection)
                ->where('scope', $scope)
                ->where('type', $type)
                ->where('period_key', $periodKey)
                ->update([
                    'last_value' => $sequence,
                    'updated_at' => $at,
                ]);

            $number = $this->compiler->compile($config['pattern'], $sequence, $at);

            $this->events->dispatch(
                new NumberAllocated($scope, $type, $periodKey, $sequence, $number),
            );
        };

        // Connection::transaction() reuses the active transaction when one is
        // present (so for gap-free types the row lock is held until the
        // caller's commit) and otherwise opens a fresh one. The attempt count
        // lets the framework retry the whole transaction on a deadlock or
        // "database is locked" error — only effective when we own the outer
        // transaction; nested calls run exactly once.
        try {
            $connection->transaction($allocate, $this->lockAttempts());
        } catch (QueryException $e) {
            throw new SequenceLocked($scope, $type, $periodKey, $e);
        }

        return $number;
    }

    /**
     * Return the number next() would produce, without consuming it.
     */
    public function peek(string $scope, string $type): string
    {
        $config = $this->configFor($type);
        $at = $this->now();
        $periodKey = $config['reset']->periodKey($at);

        $current = $this->toSequence(
            $this->rows($this->connection())
                ->where('scope', $scope)
                ->where('type', $type)
                ->where('period_key', $periodKey)
                ->value('last_value'),
        );

        return $this->compiler->compile($config['pattern'], $current + 1, $at);
    }

    /**
     * Whether this sequence has ever consumed a number, in any period.
     *
     * Deliberately not period-scoped, unlike peek(): under a yearly reset a
     * sequence used all through last year is still an engaged sequence on
     * 1 January, and a caller gating a settings lock on it must see that.
     */
    public function hasEverAllocated(string $scope, string $type): bool
    {
        // Refuse an unknown type instead of reporting an unused sequence: a
        // typo in the type would silently unlock what the caller is guarding.
        $this->configFor($type);

        return $this->rows($this->connection())
            ->where('scope', $scope)
            ->where('type', $type)
            ->where('last_value', '>', 0)
            ->exists();
    }

    /**
     * Whether a document type is configured.
     */
    public function knows(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    /**
     * Normalised configuration for a document type.
     *
     * @return TypeConfig
     */
    public function configFor(string $type): array
    {
        $types = $this->types();

        if (! isset($types[$type]) || ! is_array($types[$type])) {
            throw UnknownDocumentType::make($type);
        }

        /** @var array<array-key, mixed> $raw */
        $raw = $types[$type];

        $pattern = $raw['pattern'] ?? '';
        $compiled = is_string($pattern) ? $pattern : '';
        $this->compiler->validate($compiled);

        return [
            'pattern' => $compiled,
            'reset' => ResetPolicy::fromConfig($raw['reset'] ?? null),
            'gap_free' => (bool) ($raw['gap_free'] ?? true),
        ];
    }

    /**
     * Ensure the counter row exists, then lock it and return its current value.
     *
     * The "ensure the row exists" insert runs first and is a no-op when the
     * row is already there. Doing a write before the locking read means the
     * transaction acquires its write lock up front, which avoids the classic
     * SQLite read-then-upgrade deadlock and lets the unique index absorb the
     * insert race between concurrent first-allocations harmlessly.
     */
    private function lockCurrentValue(
        ConnectionInterface $connection,
        string $scope,
        string $type,
        string $periodKey,
        DateTimeInterface $at,
    ): int {
        $this->rows($connection)->insertOrIgnore([
            'scope' => $scope,
            'type' => $type,
            'period_key' => $periodKey,
            'last_value' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $value = $this->rows($connection)
            ->where('scope', $scope)
            ->where('type', $type)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->value('last_value');

        // The insert above is insertOrIgnore, which swallows any failure and not
        // only a duplicate key. Reading nothing back therefore means the row was
        // never written, and starting from zero would re-issue a used number.
        if (! is_numeric($value)) {
            throw new SequenceUnreadable($scope, $type, $periodKey);
        }

        return (int) $value;
    }

    private function toSequence(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function rows(ConnectionInterface $connection): Builder
    {
        return $connection->table($this->table());
    }

    private function connection(): ConnectionInterface
    {
        $name = $this->config->get('numbering.connection');

        return $this->resolver->connection(is_string($name) ? $name : null);
    }

    private function table(): string
    {
        $table = $this->config->get('numbering.table');

        return is_string($table) ? $table : 'document_number_sequences';
    }

    private function lockAttempts(): int
    {
        $attempts = $this->config->get('numbering.lock_attempts');

        return is_int($attempts) && $attempts >= 1 ? $attempts : self::DEFAULT_LOCK_ATTEMPTS;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function types(): array
    {
        $types = $this->config->get('numbering.types');

        return is_array($types) ? $types : [];
    }

    private function now(): DateTimeInterface
    {
        return CarbonImmutable::now();
    }
}
