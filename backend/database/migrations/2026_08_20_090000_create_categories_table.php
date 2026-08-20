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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('code');
            $table->string('name');
            // Nullable, see docs/domain/items.md "GL mapping" — no forced default, a chain
            // that resolves to nothing is a data problem to catch, not paper over. No FK yet:
            // chart_of_accounts doesn't exist until later in Phase 1 (see
            // docs/phases/phase-1-master-data.md task order); add the constraint once it does.
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
