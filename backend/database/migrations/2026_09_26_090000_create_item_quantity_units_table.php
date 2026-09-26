<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Smallest increment the item's own stock UoM may be transacted in;
            // NULL = unrestricted (today's behaviour, unchanged by default). See
            // ADR 0050 — never inferred from a UoM's dimension.
            $table->decimal('quantity_precision', 19, 9)->nullable()->after('stock_uom_id');
        });
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_quantity_precision_check CHECK (quantity_precision IS NULL OR quantity_precision > 0)');

        Schema::create('item_quantity_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            // 1 uom_id = factor × the item's own stock UoM. May cross UoM
            // dimension (roll↔metre, sheet↔m²) — the one deliberate exception
            // to the global UomConversionService's same-dimension-only rule.
            $table->decimal('factor', 19, 9);
            $table->decimal('quantity_precision', 19, 9)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'item_id', 'uom_id']);
        });

        // items_company_id_id_unique and uoms_company_id_id_unique already exist
        // (2026_09_02_090000_create_item_suppliers_table.php,
        // 2026_08_21_090000_enforce_company_scoped_foreign_keys.php).
        DB::statement('ALTER TABLE item_quantity_units ADD CONSTRAINT item_quantity_units_factor_check CHECK (factor > 0)');
        DB::statement('ALTER TABLE item_quantity_units ADD CONSTRAINT item_quantity_units_precision_check CHECK (quantity_precision IS NULL OR quantity_precision > 0)');
        DB::statement('ALTER TABLE item_quantity_units ADD CONSTRAINT item_quantity_units_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE item_quantity_units ADD CONSTRAINT item_quantity_units_uom_company_fk FOREIGN KEY (company_id, uom_id) REFERENCES uoms (company_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_quantity_units');
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_quantity_precision_check');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('quantity_precision'));
    }
};
