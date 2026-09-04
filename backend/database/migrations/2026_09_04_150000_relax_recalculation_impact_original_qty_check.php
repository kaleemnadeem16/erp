<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * riv_qty_check originally required original_qty_on_hand > 0 — true for
 * every AVCO case (AvcoCounterfactualReplayer's own guard rejects a
 * non-positive pool before it ever reaches here), but not for FIFO/SPECIFIC:
 * a brand-new SPECIFIC serial, or a currently fully-depleted FIFO layer
 * group, both legitimately start from zero. counterfactual_qty_on_hand stays
 * strictly positive — a real request's proposed qty is always > 0, and
 * physical conservation (counterfactual = original + proposed) keeps it so.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recalculation_impact_versions DROP CONSTRAINT riv_qty_check');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_qty_check CHECK (original_qty_on_hand >= 0 AND counterfactual_qty_on_hand > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recalculation_impact_versions DROP CONSTRAINT riv_qty_check');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_qty_check CHECK (original_qty_on_hand > 0 AND counterfactual_qty_on_hand > 0)');
    }
};
