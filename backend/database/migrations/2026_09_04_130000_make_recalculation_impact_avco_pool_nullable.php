<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A FIFO/SPECIFIC impact version has no AVCO pool at all — LayerCounterfactualReplayer
 * identifies its layer group by (item_id, location_id, batch_id, serial_no) instead.
 * The composite FK already tolerates NULL under Postgres's default MATCH SIMPLE (any
 * NULL column skips the check), so only the column and the immutability trigger need
 * to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recalculation_impact_versions ALTER COLUMN avco_pool_id DROP NOT NULL');

        // <> yields NULL (not TRUE) when comparing NULL to a non-NULL value, so a
        // NULL-avco_pool_id row could have that column silently overwritten without
        // tripping the OR-chain below — IS DISTINCT FROM is the null-safe equivalent
        // and behaves identically to <> once both sides are non-NULL, so this is a
        // strict correctness fix, not a behaviour change for existing AVCO rows.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_recalculation_impact_version_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'recalculation impact versions cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.is_stale IS TRUE OR NEW.is_stale IS NOT TRUE
                   OR NEW.id <> OLD.id OR NEW.company_id <> OLD.company_id
                   OR NEW.recalculation_request_id <> OLD.recalculation_request_id
                   OR NEW.version_no <> OLD.version_no OR NEW.avco_pool_id IS DISTINCT FROM OLD.avco_pool_id
                   OR NEW.original_qty_on_hand <> OLD.original_qty_on_hand
                   OR NEW.original_inventory_value <> OLD.original_inventory_value
                   OR NEW.original_avg_unit_cost <> OLD.original_avg_unit_cost
                   OR NEW.counterfactual_qty_on_hand <> OLD.counterfactual_qty_on_hand
                   OR NEW.counterfactual_inventory_value <> OLD.counterfactual_inventory_value
                   OR NEW.counterfactual_avg_unit_cost <> OLD.counterfactual_avg_unit_cost
                   OR NEW.valuation_adjustment <> OLD.valuation_adjustment
                   OR NEW.computed_at <> OLD.computed_at
                THEN RAISE EXCEPTION 'recalculation impact versions are immutable except is_stale false-to-true' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_recalculation_impact_version_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'recalculation impact versions cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.is_stale IS TRUE OR NEW.is_stale IS NOT TRUE
                   OR NEW.id <> OLD.id OR NEW.company_id <> OLD.company_id
                   OR NEW.recalculation_request_id <> OLD.recalculation_request_id
                   OR NEW.version_no <> OLD.version_no OR NEW.avco_pool_id <> OLD.avco_pool_id
                   OR NEW.original_qty_on_hand <> OLD.original_qty_on_hand
                   OR NEW.original_inventory_value <> OLD.original_inventory_value
                   OR NEW.original_avg_unit_cost <> OLD.original_avg_unit_cost
                   OR NEW.counterfactual_qty_on_hand <> OLD.counterfactual_qty_on_hand
                   OR NEW.counterfactual_inventory_value <> OLD.counterfactual_inventory_value
                   OR NEW.counterfactual_avg_unit_cost <> OLD.counterfactual_avg_unit_cost
                   OR NEW.valuation_adjustment <> OLD.valuation_adjustment
                   OR NEW.computed_at <> OLD.computed_at
                THEN RAISE EXCEPTION 'recalculation impact versions are immutable except is_stale false-to-true' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
        // No cleanup of existing NULL rows here — the DELETE would itself be rejected
        // by the trigger just restored above (it unconditionally blocks DELETE), and
        // silently working around that to force a rollback would be the wrong call.
        // If FIFO/SPECIFIC impact versions exist, this SET NOT NULL fails loudly
        // rather than destroying them.
        DB::statement('ALTER TABLE recalculation_impact_versions ALTER COLUMN avco_pool_id SET NOT NULL');
    }
};
