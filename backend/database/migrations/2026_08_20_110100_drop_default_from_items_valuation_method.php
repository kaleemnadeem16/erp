<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * items.valuation_method was created with a blanket DEFAULT 'AVCO' before
 * docs/decisions/0032-per-item-valuation-method.md — that default no longer
 * reflects reality (a company may have no AVCO items at all). The column
 * stays NOT NULL; App\Modules\Inventory\Models\Item now always resolves and
 * sets it explicitly before insert, from an override or its category's
 * default, so no DB-level fallback is needed. No fluent
 * ->default(null)->change() here — Laravel's grammar for that is unreliable
 * across drivers, so this drops it directly.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE items ALTER COLUMN valuation_method DROP DEFAULT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE items ALTER COLUMN valuation_method SET DEFAULT 'AVCO'");
    }
};
