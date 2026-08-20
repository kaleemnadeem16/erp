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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts');
            $table->boolean('is_postable')->default(true);
            // Nullable — an account restricted to one currency (e.g. a USD bank account in a
            // PKR-base company); null = follows the company's base currency. See
            // docs/domain/multi-currency.md and docs/domain/general-ledger.md.
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
