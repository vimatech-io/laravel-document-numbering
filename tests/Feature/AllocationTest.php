<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vimatech\DocumentNumbering\Events\NumberAllocated;
use Vimatech\DocumentNumbering\Exceptions\UnknownDocumentType;
use Vimatech\DocumentNumbering\Facades\Numbering;

beforeEach(function (): void {
    // Pin "now" so assertions on the year/month tokens are deterministic.
    CarbonImmutable::setTestNow('2026-06-07 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('allocates sequential numbers per scope and type', function (): void {
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00003');
});

it('keeps separate counters per scope', function (): void {
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('globex', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');
});

it('keeps separate counters per type', function (): void {
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'quote')->next())->toBe('QUO-2026-00001');
});

it('peeks without consuming a number', function (): void {
    expect(Numbering::for('acme', 'invoice')->peek())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->peek())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->peek())->toBe('INV-2026-00002');
});

it('resets a yearly sequence at the year boundary', function (): void {
    CarbonImmutable::setTestNow('2026-12-31 23:59:59');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00001');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2026-00002');

    CarbonImmutable::setTestNow('2027-01-01 00:00:00');
    expect(Numbering::for('acme', 'invoice')->next())->toBe('INV-2027-00001');
});

it('resets a monthly sequence at the month boundary', function (): void {
    CarbonImmutable::setTestNow('2026-06-30 23:59:59');
    expect(Numbering::for('acme', 'credit_note')->next())->toBe('CN-2606-0001');
    expect(Numbering::for('acme', 'credit_note')->next())->toBe('CN-2606-0002');

    CarbonImmutable::setTestNow('2026-07-01 00:00:00');
    expect(Numbering::for('acme', 'credit_note')->next())->toBe('CN-2607-0001');
});

it('never resets a "never" sequence across years', function (): void {
    CarbonImmutable::setTestNow('2026-06-01 00:00:00');
    expect(Numbering::for('acme', 'ticket')->next())->toBe('T001');

    CarbonImmutable::setTestNow('2027-06-01 00:00:00');
    expect(Numbering::for('acme', 'ticket')->next())->toBe('T002');
});

it('dispatches a NumberAllocated event', function (): void {
    Event::fake([NumberAllocated::class]);

    Numbering::for('acme', 'invoice')->next();

    Event::assertDispatched(NumberAllocated::class, function (NumberAllocated $event): bool {
        return $event->scope === 'acme'
            && $event->type === 'invoice'
            && $event->sequence === 1
            && $event->number === 'INV-2026-00001';
    });
});

it('throws for an unknown document type', function (): void {
    Numbering::for('acme', 'does-not-exist')->next();
})->throws(UnknownDocumentType::class);
