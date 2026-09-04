<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0040 / docs/domain/stock-recalculation.md "Review, staleness and risk
 * routing" — every impact version is independently NORMAL_RISK or HIGH_RISK,
 * and a HIGH_RISK version requires a second independent approver.
 *
 * risk_classification joins the frozen-at-creation columns the existing
 * riv_guard trigger already protects (append-only, is_stale is the only
 * permitted transition). recalculation_approvals moves from "exactly one
 * decision per version" to "one decision per (version, approver)" — the new
 * guard_recalculation_approval_insert trigger bounds the count by the
 * version's own required-approval tier and makes a REJECTED decision
 * terminal (no further decisions accepted once one lands), and derives
 * whether *this* row is the one that completes the requirement — only that
 * row may carry expires_at, since approved-execution authority doesn't exist
 * until the requirement is met.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recalculation_impact_versions', function (Blueprint $table) {
            $table->string('risk_classification')->default('NORMAL_RISK');
        });
        DB::statement("ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_risk_classification_check CHECK (risk_classification IN ('NORMAL_RISK', 'HIGH_RISK'))");

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('recalculation_high_risk_threshold_base_amount', 19, 4)->nullable();
        });
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_recalculation_high_risk_threshold_check CHECK (recalculation_high_risk_threshold_base_amount IS NULL OR recalculation_high_risk_threshold_base_amount > 0)');

        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT recalculation_approvals_company_id_impact_version_id_unique');
        DB::statement('ALTER TABLE recalculation_approvals ADD CONSTRAINT recalculation_approvals_company_id_impact_version_id_decided_by_unique UNIQUE (company_id, impact_version_id, decided_by)');
        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT ra_expiry_shape_check');
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_rejected_no_expiry_check CHECK (decision <> 'REJECTED' OR expires_at IS NULL)");

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
                   OR NEW.risk_classification <> OLD.risk_classification
                THEN RAISE EXCEPTION 'recalculation impact versions are immutable except is_stale false-to-true' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            -- A REJECTED decision is terminal: no further decisions of any kind are
            -- accepted for that version once one lands. Otherwise, the version's own
            -- risk_classification bounds how many APPROVED/REJECTED rows it may ever
            -- receive (1 for NORMAL_RISK, 2 for HIGH_RISK — the unique constraint on
            -- (impact_version_id, decided_by) already guarantees they're distinct
            -- approvers). Only the row that brings the approved count up to that
            -- required number may carry expires_at — approved-execution authority is
            -- a property of the version being fully approved, not of any one decision.
            CREATE OR REPLACE FUNCTION guard_recalculation_approval_insert() RETURNS trigger AS $$
            DECLARE
                required_count int;
                existing_count int;
                existing_rejected boolean;
                approved_count_before int;
                would_complete boolean;
            BEGIN
                SELECT CASE risk_classification WHEN 'HIGH_RISK' THEN 2 ELSE 1 END INTO required_count
                    FROM recalculation_impact_versions WHERE id = NEW.impact_version_id;
                SELECT count(*), bool_or(decision = 'REJECTED'), count(*) FILTER (WHERE decision = 'APPROVED')
                    INTO existing_count, existing_rejected, approved_count_before
                    FROM recalculation_approvals WHERE impact_version_id = NEW.impact_version_id;

                IF existing_rejected THEN
                    RAISE EXCEPTION 'this impact version has already been rejected — no further decisions are accepted' USING ERRCODE = '23514';
                END IF;
                IF existing_count >= required_count THEN
                    RAISE EXCEPTION 'this impact version already has its required number of approval decisions' USING ERRCODE = '23514';
                END IF;

                would_complete := NEW.decision = 'APPROVED' AND (approved_count_before + 1) >= required_count;
                IF would_complete AND NEW.expires_at IS NULL THEN
                    RAISE EXCEPTION 'the approval decision that completes an impact version''s requirement must set an expiry' USING ERRCODE = '23514';
                END IF;
                IF NOT would_complete AND NEW.expires_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a partial approval must not set an expiry — execution authority does not exist until the requirement is met' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER ra_guard BEFORE INSERT ON recalculation_approvals FOR EACH ROW EXECUTE FUNCTION guard_recalculation_approval_insert();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ra_guard ON recalculation_approvals');
        DB::statement('DROP FUNCTION IF EXISTS guard_recalculation_approval_insert()');

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

        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_expiry_shape_check CHECK ((decision = 'APPROVED') = (expires_at IS NOT NULL))");
        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT ra_rejected_no_expiry_check');
        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT recalculation_approvals_company_id_impact_version_id_decided_by_unique');
        DB::statement('ALTER TABLE recalculation_approvals ADD CONSTRAINT recalculation_approvals_company_id_impact_version_id_unique UNIQUE (company_id, impact_version_id)');

        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_recalculation_high_risk_threshold_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('recalculation_high_risk_threshold_base_amount');
        });

        DB::statement('ALTER TABLE recalculation_impact_versions DROP CONSTRAINT riv_risk_classification_check');
        Schema::table('recalculation_impact_versions', function (Blueprint $table) {
            $table->dropColumn('risk_classification');
        });
    }
};
