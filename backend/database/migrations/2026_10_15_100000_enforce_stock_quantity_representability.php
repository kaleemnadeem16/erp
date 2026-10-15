<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0142: the database refuses what the stock ledger cannot hold exactly, behind the model rule (ItemQuantityRules), so no raw write can leave an
 * unreachable increment or a package content finer than four places. Conversion factors stay nine places (evidence) and nothing is rewritten.
 *
 * A package is a physical stock fact, so its content and increment are bound unconditionally; an item's own increment is bound only while the item is
 * stocked, because the contexts of a non-stocked (service or technical) item are decided by their own destination, not by the stock ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE item_packagings ADD CONSTRAINT item_packagings_content_stock_scale_check CHECK (stock_quantity = round(stock_quantity, 4))');
        DB::statement('ALTER TABLE item_packagings ADD CONSTRAINT item_packagings_increment_reachable_check CHECK (quantity_precision IS NULL OR (quantity_precision >= 0.0001 AND quantity_precision = round(quantity_precision, 4)))');
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_stocked_increment_reachable_check CHECK (quantity_precision IS NULL OR NOT is_stocked OR (quantity_precision >= 0.0001 AND quantity_precision = round(quantity_precision, 4)))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_stocked_increment_reachable_check');
        DB::statement('ALTER TABLE item_packagings DROP CONSTRAINT IF EXISTS item_packagings_increment_reachable_check');
        DB::statement('ALTER TABLE item_packagings DROP CONSTRAINT IF EXISTS item_packagings_content_stock_scale_check');
    }
};
