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
        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('party_id')->constrained();
            $table->string('code');
            $table->unsignedSmallInteger('payment_terms_days');
            // General default; App\Modules\Inventory's item_suppliers.lead_time_days (Phase 2,
            // ADR 0033) overrides this per item.
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->foreignId('payable_account_id')->constrained('chart_of_accounts');
            $table->foreignId('default_currency_id')->nullable()->constrained('currencies');
            $table->jsonb('custom_fields')->nullable()->default('{}');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'party_id']);
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
    }
};
