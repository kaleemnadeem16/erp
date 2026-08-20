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
        Schema::table('categories', function (Blueprint $table) {
            // Nullable, resolved the same way as *_account_id — an item's category, or the
            // nearest ancestor with this column set, walking parent_id. See
            // docs/domain/items.md "Valuation method" and
            // docs/decisions/0032-per-item-valuation-method.md.
            $table->string('default_valuation_method')->nullable()->after('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('default_valuation_method');
        });
    }
};
