<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_moves', function (Blueprint $table) {
            // Legacy outbound AVCO facts cannot distinguish an issue from an exact receipt reversal.
            $table->string('movement_kind')->default('LEGACY');
            $table->unsignedBigInteger('reverses_move_id')->nullable();
        });
        DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT sm_reversal_company_fk FOREIGN KEY (company_id, reverses_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement("ALTER TABLE stock_moves ADD CONSTRAINT sm_kind_check CHECK (movement_kind IN ('LEGACY', 'RECEIPT', 'ISSUE', 'TRANSFER', 'REVERSAL') AND ((movement_kind = 'REVERSAL') = (reverses_move_id IS NOT NULL)))");
        Schema::table('recalculation_requests', function (Blueprint $table) {
            $table->string('movement_kind')->default('RECEIPT');
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('unit_cost', 19, 4)->nullable()->change();
            $table->foreignId('currency_id')->nullable()->change();
            $table->decimal('fx_rate', 24, 9)->nullable()->change();
            $table->decimal('base_unit_cost', 19, 4)->nullable()->change();
        });
        DB::statement('ALTER TABLE recalculation_requests DROP CONSTRAINT rr_qty_check');
        DB::statement("ALTER TABLE recalculation_requests ADD CONSTRAINT rr_movement_check CHECK (
            qty > 0 AND movement_kind IN ('RECEIPT', 'ISSUE', 'TRANSFER')
            AND ((movement_kind = 'TRANSFER') = (destination_location_id IS NOT NULL))
            AND (destination_location_id IS NULL OR destination_location_id <> location_id)
            AND ((movement_kind = 'RECEIPT' AND unit_cost IS NOT NULL AND unit_cost >= 0 AND currency_id IS NOT NULL
                  AND fx_rate IS NOT NULL AND fx_rate > 0 AND base_unit_cost IS NOT NULL AND base_unit_cost > 0)
                 OR (movement_kind <> 'RECEIPT' AND unit_cost IS NULL AND currency_id IS NULL AND fx_rate IS NULL AND base_unit_cost IS NULL)))");
        Schema::table('recalculation_impact_versions', function (Blueprint $table) {
            $table->jsonb('movement_impact')->nullable();
        });
        // A proposed issue can legitimately exhaust the affected stock completely.
        DB::statement('ALTER TABLE recalculation_impact_versions DROP CONSTRAINT riv_qty_check');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_qty_check CHECK (original_qty_on_hand >= 0 AND counterfactual_qty_on_hand >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recalculation_impact_versions DROP CONSTRAINT riv_qty_check');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_qty_check CHECK (original_qty_on_hand >= 0 AND counterfactual_qty_on_hand > 0)');
        Schema::table('recalculation_impact_versions', fn (Blueprint $table) => $table->dropColumn('movement_impact'));
        DB::statement('ALTER TABLE recalculation_requests DROP CONSTRAINT rr_movement_check');
        Schema::table('recalculation_requests', function (Blueprint $table) {
            $table->dropForeign(['destination_location_id']);
            $table->dropColumn(['movement_kind', 'destination_location_id']);
            $table->decimal('unit_cost', 19, 4)->nullable(false)->change();
            $table->foreignId('currency_id')->nullable(false)->change();
            $table->decimal('fx_rate', 24, 9)->nullable(false)->change();
            $table->decimal('base_unit_cost', 19, 4)->nullable(false)->change();
        });
        DB::statement('ALTER TABLE recalculation_requests ADD CONSTRAINT rr_qty_check CHECK (qty > 0 AND unit_cost >= 0 AND base_unit_cost > 0 AND fx_rate > 0)');
        DB::statement('ALTER TABLE stock_moves DROP CONSTRAINT sm_kind_check');
        DB::statement('ALTER TABLE stock_moves DROP CONSTRAINT sm_reversal_company_fk');
        Schema::table('stock_moves', fn (Blueprint $table) => $table->dropColumn(['movement_kind', 'reverses_move_id']));
    }
};
