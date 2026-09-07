<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0040 "authority-only rerouting":
 * `AUTHORITY_ONLY_CHANGE: REROUTE_OR_INVALIDATE_APPROVAL_WITHOUT_NEW_IMPACT_VERSION`.
 * guard_recalculation_approval_insert previously bounded a version's
 * required-approver cap by every distinct approver who had *ever* approved,
 * regardless of expiry — so once the cap was reached, no other actor could
 * ever decide again, even after every existing approval expired. That
 * distinct_approved_actors count now only counts each approver's most
 * recent APPROVED decision when it is still unexpired, so an expired slot
 * is open for a different eligible approver to claim without a new impact
 * version — the same relaxation DecideRecalculationApproval now applies via
 * RecalculationImpactVersion::unexpiredApprovedActorIds(). The same-actor
 * renewal exemption (actor_previously_approved) and the one-live-decision-
 * per-actor and terminal-rejection guards are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_recalculation_approval_insert() RETURNS trigger AS $$
            DECLARE
                required_count int;
                existing_rejected boolean;
                actor_has_active_approval boolean;
                actor_previously_approved boolean;
                distinct_unexpired_approved_actors int;
            BEGIN
                SELECT CASE risk_classification WHEN 'HIGH_RISK' THEN 2 ELSE 1 END INTO required_count
                    FROM recalculation_impact_versions WHERE id = NEW.impact_version_id;

                SELECT bool_or(decision = 'REJECTED'),
                       bool_or(decision = 'APPROVED' AND decided_by = NEW.decided_by AND expires_at > now()),
                       bool_or(decision = 'APPROVED' AND decided_by = NEW.decided_by)
                    INTO existing_rejected, actor_has_active_approval, actor_previously_approved
                    FROM recalculation_approvals WHERE impact_version_id = NEW.impact_version_id;

                -- Each approver's own most recent APPROVED decision is the
                -- one that speaks for their slot now (a renewal supersedes
                -- their own earlier row without deleting it); only slots
                -- still unexpired count against the cap for a new claimant.
                SELECT count(*) INTO distinct_unexpired_approved_actors
                    FROM (
                        SELECT DISTINCT ON (decided_by) decided_by, expires_at
                        FROM recalculation_approvals
                        WHERE impact_version_id = NEW.impact_version_id AND decision = 'APPROVED'
                        ORDER BY decided_by, id DESC
                    ) latest
                    WHERE latest.expires_at > now();

                IF existing_rejected THEN
                    RAISE EXCEPTION 'this impact version has already been rejected — no further decisions are accepted' USING ERRCODE = '23514';
                END IF;
                IF actor_has_active_approval THEN
                    RAISE EXCEPTION 'this actor already holds an unexpired decision on this impact version' USING ERRCODE = '23514';
                END IF;
                IF NOT actor_previously_approved AND distinct_unexpired_approved_actors >= required_count THEN
                    RAISE EXCEPTION 'this impact version already has its required number of currently-unexpired approvers' USING ERRCODE = '23514';
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
};
