<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vimatech\DocumentNumbering\Exceptions\UnknownDocumentType;
use Vimatech\DocumentNumbering\Facades\Numbering;
use Vimatech\DocumentNumbering\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-07 12:00:00');
});

afterEach(function (): void {
    Schema::dropIfExists('invoices');
    CarbonImmutable::setTestNow();
});

it('is false for a sequence that has never allocated', function (): void {
    expect(Numbering::for('acme', 'invoice')->hasAllocated())->toBeFalse();
});

it('is true once a number has been allocated', function (): void {
    Numbering::for('acme', 'invoice')->next();

    expect(Numbering::for('acme', 'invoice')->hasAllocated())->toBeTrue();
});

it('stays true after a yearly reset, when peek() reports a fresh sequence', function (): void {
    CarbonImmutable::setTestNow('2026-11-20 09:00:00');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');

    CarbonImmutable::setTestNow('2027-01-01 00:00:01');

    // The current period is empty, so peek() offers number 1 again — correct
    // for peek, and exactly why it cannot answer "is this sequence engaged?".
    expect(Numbering::for('acme', 'invoice')->peek())->toBe('INV-2027-00001');
    expect(Numbering::for('acme', 'invoice')->hasAllocated())->toBeTrue();
});

it('stays true after a monthly reset', function (): void {
    CarbonImmutable::setTestNow('2026-06-30 23:59:59');
    Numbering::for('acme', 'credit_note')->next();

    CarbonImmutable::setTestNow('2026-07-01 00:00:00');

    expect(Numbering::for('acme', 'credit_note')->hasAllocated())->toBeTrue();
});

it('is scoped to its own scope and type', function (): void {
    Numbering::for('acme', 'invoice')->next();

    expect(Numbering::for('globex', 'invoice')->hasAllocated())->toBeFalse();
    expect(Numbering::for('acme', 'quote')->hasAllocated())->toBeFalse();
});

it('does not treat a materialised counter row as an allocation', function (): void {
    // Allocation creates the counter row at zero before incrementing it, so
    // the row existing is not the same thing as a number having been taken.
    DB::table('document_number_sequences')->insert([
        'scope' => 'acme',
        'type' => 'invoice',
        'period_key' => '2026',
        'last_value' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Numbering::for('acme', 'invoice')->hasAllocated())->toBeFalse();
});

it('stays true after the document carrying the number is deleted', function (): void {
    Schema::create('invoices', function (Blueprint $table): void {
        $table->id();
        $table->string('company_id');
        $table->string('number')->nullable();
    });

    Invoice::create(['company_id' => 'acme'])->delete();

    // The counter is monotonic and independent of the caller's models: a
    // number stays consumed whatever happens to the document that carries it.
    expect(Invoice::query()->count())->toBe(0);
    expect(Numbering::for('acme', 'invoice')->hasAllocated())->toBeTrue();
});

it('consumes nothing', function (): void {
    Numbering::for('acme', 'invoice')->next();

    Numbering::for('acme', 'invoice')->hasAllocated();

    expect(Numbering::for('acme', 'invoice')->peek())->toBe('INV-2026-00002');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');
});

it('refuses an unknown document type instead of reporting an unused sequence', function (): void {
    Numbering::for('acme', 'does-not-exist')->hasAllocated();
})->throws(UnknownDocumentType::class);
