<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('from_currency_id')->constrained('currencies');
            $table->foreignId('to_currency_id')->constrained('currencies');
            $table->decimal('rate', 19, 9);
            $table->date('rate_date');
            $table->string('rate_type');
            // ADR 0031 currently permits manual entry only. The nullable source remains so a
            // future provider can be recorded without changing historical rows or this schema.
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'from_currency_id', 'to_currency_id', 'rate_date', 'rate_type'],
                'exchange_rates_unique_rate'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
