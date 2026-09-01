<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vimatech\DocumentNumbering\Exceptions\SequenceUnreadable;
use Vimatech\DocumentNumbering\NumberingManager;

it('refuses to allocate when the sequence row cannot be read back', function () {
    // A numbering table carrying a column the package does not populate. The
    // "ensure the row exists" insert is an insertOrIgnore, so the constraint
    // failure is swallowed and the row is never written.
    Schema::create('sequences_with_extra_column', function (Blueprint $table): void {
        $table->id();
        $table->string('scope');
        $table->string('type');
        $table->string('period_key');
        $table->unsignedBigInteger('last_value')->default(0);
        $table->string('mandatory');
        $table->timestamps();
        $table->unique(['scope', 'type', 'period_key']);
    });

    config()->set('numbering.table', 'sequences_with_extra_column');

    expect(fn () => app(NumberingManager::class)->next('default', 'invoice'))
        ->toThrow(SequenceUnreadable::class);
});
