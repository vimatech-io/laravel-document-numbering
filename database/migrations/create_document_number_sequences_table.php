<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->string('type');
            $table->string('period_key');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'type', 'period_key'], 'document_number_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('numbering.table', 'document_number_sequences');
    }
};
