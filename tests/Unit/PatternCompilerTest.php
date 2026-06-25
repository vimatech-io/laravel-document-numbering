<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vimatech\DocumentNumbering\Exceptions\InvalidPattern;
use Vimatech\DocumentNumbering\Support\PatternCompiler;

beforeEach(function (): void {
    $this->compiler = new PatternCompiler;
    $this->at = CarbonImmutable::create(2026, 6, 7, 12, 0, 0);
});

it('renders year, month and zero-padded sequence tokens', function (): void {
    expect($this->compiler->compile('INV-{YYYY}-{seq:5}', 42, $this->at))
        ->toBe('INV-2026-00042');
});

it('supports the 2-digit year and month tokens', function (): void {
    expect($this->compiler->compile('CN-{YY}{MM}-{seq:4}', 7, $this->at))
        ->toBe('CN-2606-0007');
});

it('keeps literal text verbatim and supports no tokens at all', function (): void {
    expect($this->compiler->compile('FIXED', 1, $this->at))->toBe('FIXED');
});

it('does not truncate a sequence longer than its padding', function (): void {
    expect($this->compiler->compile('{seq:3}', 12345, $this->at))->toBe('12345');
});

it('throws on an unknown token', function (): void {
    $this->compiler->compile('INV-{NOPE}', 1, $this->at);
})->throws(InvalidPattern::class);

it('validates patterns without rendering them', function (): void {
    $this->compiler->validate('INV-{YYYY}-{seq:5}');

    expect(fn () => $this->compiler->validate('INV-{WAT}'))
        ->toThrow(InvalidPattern::class);
});
