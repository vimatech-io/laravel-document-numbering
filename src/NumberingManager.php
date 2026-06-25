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
     * How many times the framework may retry the allocation transaction when
     * the database reports a deadlock or a busy/locked error.
     */
    private const MAX_LOCK_ATTEMPTS = 5;

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

        $allocate = function () use ($connection, $scope, $type, $periodKey, $config, $at): string {
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

            return $number;
        };

        // Connection::transaction() reuses the active transaction when one is
        // present (so for gap-free types the row lock is held until the
        // caller's commit) and otherwise opens a fresh one. The attempt count
        // lets the framework retry the whole transaction on a deadlock or
        // "database is locked" error — only effective when we own the outer
        // transaction; nested calls run exactly once.
        try {
            $number = $connection->transaction($allocate, self::MAX_LOCK_ATTEMPTS);
        } catch (QueryException $e) {
            throw new SequenceLocked($scope, $type, $periodKey, $e);
        }

        // transaction() is declared to return mixed; our callback always
        // returns the formatted number.
        if (! is_string($number)) {
            throw new SequenceLocked($scope, $type, $periodKey);
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
        $reset = $raw['reset'] ?? ResetPolicy::Never;

        $compiled = is_string($pattern) ? $pattern : '';
        $this->compiler->validate($compiled);

        return [
            'pattern' => $compiled,
            'reset' => $this->normaliseReset($reset),
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

        return $this->toSequence(
            $this->rows($connection)
                ->where('scope', $scope)
                ->where('type', $type)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->value('last_value'),
        );
    }

    private function normaliseReset(mixed $reset): ResetPolicy
    {
        if ($reset instanceof ResetPolicy) {
            return $reset;
        }

        return is_string($reset) ? ResetPolicy::from($reset) : ResetPolicy::Never;
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
