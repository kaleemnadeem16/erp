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
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('party_id')->constrained();
            $table->string('code');
            // Nullable — null means no limit enforced, not zero credit.
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->unsignedSmallInteger('payment_terms_days');
            // Nullable, no FK yet: tax_codes doesn't exist yet — same forward-reference
            // reason as items.default_supplier_id. See docs/domain/tax.md.
            $table->unsignedBigInteger('tax_code_id')->nullable();
            // The customer's control account (an ASSET) — not income, see
            // docs/domain/parties.md "Receivable and payable are control accounts, not income".
            $table->foreignId('receivable_account_id')->constrained('chart_of_accounts');
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
        Schema::dropIfExists('customer_accounts');
    }
};
