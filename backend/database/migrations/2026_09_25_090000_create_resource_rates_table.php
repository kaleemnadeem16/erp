<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The LABOUR/PLANT estimating-rate source Step 3 needs — see
 * docs/domain/assemblies.md "Resource rates". Mirrors exchange_rates'
 * lightweight, dated-row shape deliberately, not formulation_versions'/
 * overhead_policies' heavier DRAFT/ACTIVE/SUPERSEDED lifecycle: a resource
 * rate is a market fact (like AVCO cost), not a governed policy needing a
 * reason/audit trail. A correction is a new row with a later effective_from,
 * never an edit to an already-used historical row — there is deliberately
 * no update endpoint (see ResourceRateController).
 *
 * No effective_to: resolution is always "the latest row with
 * effective_from <= the target date," same rule documented (but not
 * previously implemented anywhere) for exchange_rates — see
 * docs/domain/multi-currency.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->decimal('rate', 19, 6);
            $table->unsignedBigInteger('rate_uom_id');
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['company_id', 'item_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE resource_rates ADD CONSTRAINT resource_rates_rate_check CHECK (rate >= 0)');
        DB::statement('ALTER TABLE resource_rates ADD CONSTRAINT resource_rates_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE resource_rates ADD CONSTRAINT resource_rates_rate_uom_company_fk FOREIGN KEY (company_id, rate_uom_id) REFERENCES uoms (company_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_rates');
    }
};
