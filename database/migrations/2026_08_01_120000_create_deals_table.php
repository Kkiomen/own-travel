<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('source', 64);
            $table->string('type', 16);
            $table->string('title');
            $table->text('url');
            $table->unsignedInteger('price_minor_units');
            $table->string('price_currency', 3);
            $table->string('origin_iata', 3)->nullable();
            $table->string('origin_name')->nullable();
            $table->string('destination_iata', 3)->nullable();
            $table->string('destination_name')->nullable();
            $table->string('destination_country')->nullable();
            $table->timestamp('departs_at')->nullable();
            $table->timestamp('found_at');
            $table->timestamps();

            $table->index(['type', 'price_minor_units']);
            $table->index('found_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
