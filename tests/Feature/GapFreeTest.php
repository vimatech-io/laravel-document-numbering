<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vimatech\DocumentNumbering\Facades\Numbering;
use Vimatech\DocumentNumbering\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-07 12:00:00');

    Schema::create('invoices', function (Blueprint $table): void {
        $table->id();
        $table->string('company_id');
        $table->string('number')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('invoices');
    CarbonImmutable::setTestNow();
});

it('releases a gap-free number when the surrounding transaction rolls back', function (): void {
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');

    try {
        DB::transaction(function (): void {
            Numbering::for('acme', 'invoice')->next(); // would be 00002
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The rolled-back number is handed back out, so there is no gap.
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');
});

it('assigns a number to a model on creation via the trait', function (): void {
    $invoice = Invoice::create(['company_id' => 'acme']);

    expect($invoice->number)->toBe('INV-2026-00001');

    $second = Invoice::create(['company_id' => 'acme']);
    expect($second->number)->toBe('INV-2026-00002');
});

it('keeps per-scope counters when allocating through the trait', function (): void {
    $acme = Invoice::create(['company_id' => 'acme']);
    $globex = Invoice::create(['company_id' => 'globex']);

    expect($acme->number)->toBe('INV-2026-00001');
    expect($globex->number)->toBe('INV-2026-00001');
});

it('does not consume a number when the model creation transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            Invoice::create(['company_id' => 'acme']); // takes 00001
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Because the type is gap-free, the rolled-back number is reused.
    $invoice = Invoice::create(['company_id' => 'acme']);
    expect($invoice->number)->toBe('INV-2026-00001');
});
