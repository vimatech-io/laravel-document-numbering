<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Vimatech\DocumentNumbering\Enums\ResetPolicy;
use Vimatech\DocumentNumbering\Facades\Numbering;
use Vimatech\DocumentNumbering\NumberingManager;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-07 12:00:00');

    // A file-backed connection so a reconnect does not wipe the data (an
    // in-memory database is destroyed when its single connection closes).
    $this->dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vimatech-worker-'.getmypid().'.sqlite';

    foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    touch($this->dbPath);

    config()->set('database.connections.worker', [
        'driver' => 'sqlite',
        'database' => $this->dbPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
        'journal_mode' => 'wal',
        'synchronous' => 'normal',
    ]);
    config()->set('database.default', 'worker');
    config()->set('numbering.connection', 'worker');

    DB::purge('worker');
    Artisan::call('migrate:fresh', [
        '--database' => 'worker',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

it('keeps a single long-lived manager instance across resolutions', function (): void {
    // Under FrankenPHP/Octane the container survives across requests, so the
    // manager must be a stable, reusable singleton.
    expect(app(NumberingManager::class))->toBe(app(NumberingManager::class));
});

it('reads configuration on demand rather than freezing a snapshot', function (): void {
    $manager = app(NumberingManager::class);

    expect($manager->knows('purchase_order'))->toBeFalse();

    // Simulate config being present/changed without rebuilding the singleton.
    config()->set('numbering.types.purchase_order', [
        'pattern' => 'PO-{YYYY}-{seq:4}',
        'reset' => ResetPolicy::Yearly,
        'gap_free' => true,
    ]);

    expect($manager->knows('purchase_order'))->toBeTrue();
    expect($manager->for('acme', 'purchase_order')->next())->toBe('PO-2026-0001');
});

it('survives a connection reconnect between simulated requests', function (): void {
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');

    // Octane reconnects the database between requests; the manager resolves the
    // connection per call, so it must not hold a stale handle.
    DB::purge('worker');

    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');
});
