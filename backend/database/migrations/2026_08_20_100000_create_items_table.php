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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');
            $table->foreignId('stock_uom_id')->constrained('uoms');
            $table->boolean('is_stocked')->default(false);
            $table->boolean('is_purchasable')->default(false);
            $table->boolean('is_sellable')->default(false);
            // A plain string, not an enum-backed column: AVCO is the only method in use
            // today (ADR 0007) and nothing in the domain treats this as a real choice yet.
            $table->string('valuation_method')->default('AVCO');
            $table->string('tracking')->default('NONE');
            $table->foreignId('category_id')->constrained();
            // Nullable, no FK yet: supplier_accounts doesn't exist until later in Phase 1
            // (see docs/phases/phase-1-master-data.md task order); add the constraint then.
            $table->unsignedBigInteger('default_supplier_id')->nullable();
            // Nullable, see docs/domain/items.md "GL mapping" — falls through to the item's
            // category when unset, same as categories.*_account_id. No FK yet:
            // chart_of_accounts doesn't exist until later in this phase either.
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            // Nullable, defaulting to '{}' — see App\Support\Concerns\HasCustomFields.
            $table->jsonb('custom_fields')->nullable()->default('{}');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
