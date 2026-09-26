<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('work_uom_id');
            $table->decimal('qty_per_work_unit', 19, 6);
            $table->unsignedBigInteger('uom_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        // items_company_id_id_unique and uoms_company_id_id_unique already exist
        // (2026_09_02_090000_create_item_suppliers_table.php,
        // 2026_08_21_090000_enforce_company_scoped_foreign_keys.php) — no need to
        // add them again before referencing them below.
        DB::statement('ALTER TABLE application_rates ADD CONSTRAINT application_rates_qty_check CHECK (qty_per_work_unit > 0)');
        DB::statement('ALTER TABLE application_rates ADD CONSTRAINT application_rates_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE application_rates ADD CONSTRAINT application_rates_work_uom_company_fk FOREIGN KEY (company_id, work_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE application_rates ADD CONSTRAINT application_rates_uom_company_fk FOREIGN KEY (company_id, uom_id) REFERENCES uoms (company_id, id)');
        // At most one default rate per item+work-unit — application-rates.md's fallback
        // chain only makes sense if "the default" is unambiguous.
        DB::statement('CREATE UNIQUE INDEX application_rates_one_default ON application_rates (company_id, item_id, work_uom_id) WHERE is_default');
    }

    public function down(): void
    {
        Schema::dropIfExists('application_rates');
    }
};
