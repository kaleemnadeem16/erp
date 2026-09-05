<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0040 "Approved execution lifetime": APPROVAL_TTL_TARGET is
 * EACH_REQUIRED_APPROVAL, not just the decision that happens to complete the
 * requirement. A HIGH_RISK version's first approval must carry its own
 * expiry the moment it's granted, same as the second. And "every expired
 * required tier must be approved again before execution" means the same
 * approver can append a fresh decision once their own grant has lapsed — an
 * append, since approvals stay immutable; the old, now-expired row is never
 * touched.
 *
 * This is renewal, not rerouting: it reuses the same approver's existing
 * slot. A different person taking over a slot is authority-only rerouting,
 * a separate, still-unbuilt ADR 0040 mechanism — this migration does not
 * touch that.
 *
 * Two schema consequences:
 *  - The one-decision-per-(version, approver) unique constraint has to go —
 *    a renewal is a second row for the same pair. guard_recalculation_approval_insert
 *    now enforces the equivalent live rule instead: an approver may not hold
 *    two simultaneously-unexpired decisions on one version, and a version's
 *    required-approver-count cap is measured in distinct approvers, not raw
 *    row count, so a renewal never consumes a new slot.
 *  - Every APPROVED row carries expires_at again (ra_expiry_shape_check,
 *    replacing the narrower ra_rejected_no_expiry_check) — expiry is now a
 *    property of each decision, not of whichever one happened to complete
 *    the requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT recalculation_approvals_company_id_impact_version_id_decided_by_unique');
        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT ra_rejected_no_expiry_check');
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_expiry_shape_check CHECK ((decision = 'APPROVED') = (expires_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            -- Required-approver count is now measured in distinct decided_by
            -- among APPROVED rows, not raw row count, so a renewal (another
            -- row from an approver who already holds a slot) never counts as
            -- a new approver and never over-fills the version's tier.
            CREATE OR REPLACE FUNCTION guard_recalculation_approval_insert() RETURNS trigger AS $$
            DECLARE
                required_count int;
                existing_rejected boolean;
                actor_has_active_approval boolean;
                actor_previously_approved boolean;
                distinct_approved_actors int;
            BEGIN
                SELECT CASE risk_classification WHEN 'HIGH_RISK' THEN 2 ELSE 1 END INTO required_count
                    FROM recalculation_impact_versions WHERE id = NEW.impact_version_id;

                SELECT bool_or(decision = 'REJECTED'),
                       bool_or(decision = 'APPROVED' AND decided_by = NEW.decided_by AND expires_at > now()),
                       bool_or(decision = 'APPROVED' AND decided_by = NEW.decided_by),
                       count(DISTINCT decided_by) FILTER (WHERE decision = 'APPROVED')
                    INTO existing_rejected, actor_has_active_approval, actor_previously_approved, distinct_approved_actors
                    FROM recalculation_approvals WHERE impact_version_id = NEW.impact_version_id;

                IF existing_rejected THEN
                    RAISE EXCEPTION 'this impact version has already been rejected — no further decisions are accepted' USING ERRCODE = '23514';
                END IF;
                IF actor_has_active_approval THEN
                    RAISE EXCEPTION 'this actor already holds an unexpired decision on this impact version' USING ERRCODE = '23514';
                END IF;
                IF NOT actor_previously_approved AND distinct_approved_actors >= required_count THEN
                    RAISE EXCEPTION 'this impact version already has its required number of independent approvers' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
            SQL);

        DB::statement('ALTER TABLE recalculation_approvals DROP CONSTRAINT ra_expiry_shape_check');
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_rejected_no_expiry_check CHECK (decision <> 'REJECTED' OR expires_at IS NULL)");
        DB::statement('ALTER TABLE recalculation_approvals ADD CONSTRAINT recalculation_approvals_company_id_impact_version_id_decided_by_unique UNIQUE (company_id, impact_version_id, decided_by)');
    }
};
