<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * categories.*_account_id shipped unconstrained (see the creating migration's
 * comment) — chart_of_accounts didn't exist yet. It does now; wire the real FKs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('income_account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('expense_account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('inventory_account_id')->references('id')->on('chart_of_accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['income_account_id']);
            $table->dropForeign(['expense_account_id']);
            $table->dropForeign(['inventory_account_id']);
        });
    }
};
