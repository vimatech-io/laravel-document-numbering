<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vimatech\DocumentNumbering\Facades\Numbering;

beforeEach(function (): void {
    // Forking requires a real, shared database file (not :memory:), so this
    // test runs against its own SQLite connection with a generous busy timeout
    // and WAL journalling for better writer concurrency.
    $this->dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vimatech-concurrency-'.getmypid().'.sqlite';

    foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    touch($this->dbPath);

    config()->set('database.connections.concurrency', [
        'driver' => 'sqlite',
        'database' => $this->dbPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
        'journal_mode' => 'wal',
        'synchronous' => 'normal',
    ]);
    config()->set('database.default', 'concurrency');
    // The manager reads its connection from config on demand, so the existing
    // singleton picks this up without being rebuilt — the same property that
    // makes it safe under worker mode.
    config()->set('numbering.connection', 'concurrency');

    DB::purge('concurrency');
    Artisan::call('migrate:fresh', [
        '--database' => 'concurrency',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ]);

    Schema::connection('concurrency')->create('allocated_numbers', function (Blueprint $table): void {
        $table->string('value');
    });
});

afterEach(function (): void {
    foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

it('allocates with no gaps and no duplicates under concurrent processes', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for the concurrency test.');
    }

    $workers = 8;
    $perWorker = 25;
    $total = $workers * $perWorker;

    // Children inherit the parent PDO handle; closing it forces each process
    // to open its own connection to the shared SQLite file.
    DB::disconnect('concurrency');

    $pids = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork a worker process.');
        }

        if ($pid === 0) {
            // Child: allocate numbers and record each one, then exit.
            try {
                DB::reconnect('concurrency');

                for ($i = 0; $i < $perWorker; $i++) {
                    $number = Numbering::for('acme', 'invoice')->next();
                    DB::connection('concurrency')->table('allocated_numbers')->insert(['value' => $number]);
                }

                DB::disconnect('concurrency');
                exit(0);
            } catch (Throwable) {
                // A non-zero exit signals the failure to the parent, which
                // asserts that every worker completed cleanly.
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $failures = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $failures += pcntl_wexitstatus($status);
    }

    DB::reconnect('concurrency');

    expect($failures)->toBe(0);

    $values = DB::connection('concurrency')->table('allocated_numbers')->pluck('value');

    // Exactly one row per allocation, all distinct: no duplicates.
    expect($values)->toHaveCount($total);
    expect($values->unique()->values())->toHaveCount($total);

    // The emitted sequence numbers cover 1..N with no gaps.
    $sequences = $values
        ->map(fn (string $value): int => (int) substr($value, -5))
        ->sort()
        ->values()
        ->all();

    expect($sequences)->toBe(range(1, $total));

    // And the stored counter matches the number of allocations.
    expect((int) DB::connection('concurrency')->table('document_number_sequences')->value('last_value'))
        ->toBe($total);
});
