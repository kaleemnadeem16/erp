<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CA0a — the DISABLED customer-decision intake foundation (Task G, G-A0; contract docs/domain/sales-customer-decisions.md). Three non-monetary tables that record what a
 * customer said or sent, never that they agreed:
 *
 *  - `customer_decision_cases`: one shared intake per customer Party (the verified intake identity is contextual — never a PO text alone and never authority to purchase).
 *    `generation` is disabled metadata until CA1 gives it real head/event semantics; nothing writes it here.
 *  - `customer_decision_components`: the unit a decision will later be about — a case's intent identity tied to an ORIGINAL logical quotation scope. The scope is context only;
 *    it proves nothing about what was officially offered.
 *  - `customer_decision_evidence`: an append-only observation chain (source, truthful occurrence precision, representative, authority-verification basis, factual description).
 *    An observation is never consent; unknown stays unknown (NULL / UNKNOWN), never defaulted.
 *
 * Deliberately absent: any acceptance, claim, entitlement, amount, target id, receipt, attachment / protected object / digest (they wait for the shared file foundation), and any
 * issued-revision key (an issued source does not exist yet). Tenancy is company-composite keys throughout; the database also refuses to rewrite a case's identity, to delete a
 * case, to update or delete components and evidence, and to TRUNCATE any of the three. Rolling back refuses while any row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_decision_cases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('party_id');
            $t->string('intake_identity', 120);
            $t->unsignedBigInteger('generation')->default(0);
            $t->unsignedBigInteger('recorded_by');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'customer_decision_cases_company_id_unique');
            $t->unique(['company_id', 'intake_identity'], 'customer_decision_cases_intake_unique');
            $t->unique(['company_id', 'id', 'party_id'], 'customer_decision_cases_party_unique');
            $t->foreign(['company_id', 'party_id'], 'customer_decision_cases_party_fk')->references(['company_id', 'id'])->on('parties');
            $t->foreign(['company_id', 'recorded_by'], 'customer_decision_cases_recorder_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE customer_decision_cases ADD CONSTRAINT customer_decision_cases_shape CHECK (
            length(intake_identity) BETWEEN 1 AND 120 AND intake_identity = btrim(intake_identity) AND generation >= 0
        )');

        Schema::create('customer_decision_components', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('case_id');
            $t->string('intent_identity', 120);
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('original_scope_anchor_id');
            $t->unsignedBigInteger('recorded_by');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'customer_decision_components_company_id_unique');
            $t->unique(['company_id', 'case_id', 'id'], 'customer_decision_components_case_unique');
            $t->unique(['company_id', 'case_id', 'intent_identity'], 'customer_decision_components_intent_unique');
            $t->foreign(['company_id', 'case_id'], 'customer_decision_components_case_fk')->references(['company_id', 'id'])->on('customer_decision_cases');
            $t->foreign(['company_id', 'family_id'], 'customer_decision_components_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'family_id', 'original_scope_anchor_id'], 'customer_decision_components_scope_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_logical_scopes');
            $t->foreign(['company_id', 'recorded_by'], 'customer_decision_components_recorder_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE customer_decision_components ADD CONSTRAINT customer_decision_components_shape CHECK (
            length(intent_identity) BETWEEN 1 AND 120 AND intent_identity = btrim(intent_identity)
        )');

        Schema::create('customer_decision_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->string('source_kind', 30);
            $t->string('external_reference', 200)->nullable();
            $t->string('source_version', 80)->nullable();
            $t->string('customer_representative_snapshot', 500)->nullable();
            $t->string('authority_verification_basis', 30);
            $t->timestampTz('occurred_at', 6)->nullable();
            $t->timestampTz('occurred_until', 6)->nullable();
            $t->string('occurrence_precision', 12);
            $t->string('source_timezone', 64)->nullable();
            $t->text('factual_description');
            $t->string('observation_state', 20);
            $t->unsignedBigInteger('recorded_by');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'customer_decision_evidence_company_id_unique');
            $t->unique(['company_id', 'case_id', 'id'], 'customer_decision_evidence_case_unique');
            $t->foreign(['company_id', 'case_id'], 'customer_decision_evidence_case_fk')->references(['company_id', 'id'])->on('customer_decision_cases');
            $t->foreign(['company_id', 'case_id', 'predecessor_id'], 'customer_decision_evidence_predecessor_fk')->references(['company_id', 'case_id', 'id'])->on('customer_decision_evidence');
            $t->foreign(['company_id', 'recorded_by'], 'customer_decision_evidence_recorder_fk')->references(['company_id', 'id'])->on('users');
        });
        // One successor per predecessor: a version chain never forks.
        DB::statement('CREATE UNIQUE INDEX customer_decision_evidence_successor_unique ON customer_decision_evidence (company_id, predecessor_id) WHERE predecessor_id IS NOT NULL');
        DB::statement("ALTER TABLE customer_decision_evidence ADD CONSTRAINT customer_decision_evidence_shape CHECK (
            source_kind IN ('PURCHASE_ORDER','SIGNATURE','EMAIL','VERBAL','OTHER_WRITTEN')
            AND authority_verification_basis IN ('UNVERIFIED','STATED_BY_CUSTOMER','DOCUMENT_ON_FILE','CONFIRMED_BY_SELLER')
            AND observation_state IN ('OBSERVED')
            AND occurrence_precision IN ('UNKNOWN','DATE','INSTANT','INTERVAL')
            AND length(btrim(factual_description)) BETWEEN 1 AND 4000
            AND (external_reference IS NULL OR (length(btrim(external_reference)) > 0 AND external_reference = btrim(external_reference)))
            AND (source_version IS NULL OR (length(btrim(source_version)) > 0 AND source_version = btrim(source_version)))
            AND (customer_representative_snapshot IS NULL OR length(btrim(customer_representative_snapshot)) > 0)
            AND (source_timezone IS NULL OR length(btrim(source_timezone)) > 0)
            AND ((occurrence_precision = 'UNKNOWN' AND occurred_at IS NULL AND occurred_until IS NULL AND source_timezone IS NULL)
              OR (occurrence_precision = 'DATE' AND occurred_at IS NOT NULL AND occurred_until IS NULL AND source_timezone IS NOT NULL)
              OR (occurrence_precision = 'INSTANT' AND occurred_at IS NOT NULL AND occurred_until IS NULL)
              OR (occurrence_precision = 'INTERVAL' AND occurred_at IS NOT NULL AND occurred_until IS NOT NULL AND occurred_until >= occurred_at))
        )");

        DB::unprepared(<<<'SQL'
-- The recording time is the database's own clock, whatever the writer supplied.
CREATE OR REPLACE FUNCTION customer_decision_stamp_recorded_at() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER customer_decision_cases_stamp BEFORE INSERT ON customer_decision_cases FOR EACH ROW EXECUTE FUNCTION customer_decision_stamp_recorded_at();
CREATE TRIGGER customer_decision_components_stamp BEFORE INSERT ON customer_decision_components FOR EACH ROW EXECUTE FUNCTION customer_decision_stamp_recorded_at();
CREATE TRIGGER customer_decision_evidence_stamp BEFORE INSERT ON customer_decision_evidence FOR EACH ROW EXECUTE FUNCTION customer_decision_stamp_recorded_at();

-- Components and evidence are facts: never updated, never deleted, never truncated. A case is never deleted or truncated, and its identity never changes.
CREATE OR REPLACE FUNCTION customer_decision_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Customer decision components and evidence are append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER customer_decision_components_guard BEFORE UPDATE OR DELETE ON customer_decision_components FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard();
CREATE TRIGGER customer_decision_components_truncate_guard BEFORE TRUNCATE ON customer_decision_components FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard();
CREATE TRIGGER customer_decision_evidence_guard BEFORE UPDATE OR DELETE ON customer_decision_evidence FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard();
CREATE TRIGGER customer_decision_evidence_truncate_guard BEFORE TRUNCATE ON customer_decision_evidence FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard();

CREATE OR REPLACE FUNCTION customer_decision_case_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'A customer decision case is never deleted' USING ERRCODE = '23514';
 END IF;
 IF (NEW.company_id, NEW.party_id, NEW.intake_identity, NEW.recorded_by, NEW.recorded_at) IS DISTINCT FROM (OLD.company_id, OLD.party_id, OLD.intake_identity, OLD.recorded_by, OLD.recorded_at) THEN
  RAISE EXCEPTION 'A customer decision case identity is immutable' USING ERRCODE = '23514';
 END IF;
 IF NEW.generation < OLD.generation THEN
  RAISE EXCEPTION 'A customer decision case generation never moves backwards' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER customer_decision_cases_guard BEFORE UPDATE OR DELETE ON customer_decision_cases FOR EACH ROW EXECUTE FUNCTION customer_decision_case_guard();

CREATE OR REPLACE FUNCTION customer_decision_case_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Customer decision cases are never truncated' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER customer_decision_cases_truncate_guard BEFORE TRUNCATE ON customer_decision_cases FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_case_truncate_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE customer_decision_evidence, customer_decision_components, customer_decision_cases IN ACCESS EXCLUSIVE MODE');
        if (DB::table('customer_decision_evidence')->exists() || DB::table('customer_decision_components')->exists() || DB::table('customer_decision_cases')->exists()) {
            throw new RuntimeException('Customer decision records exist: rolling back would destroy evidence. Reconcile them under a separately approved plan first.');
        }
        Schema::dropIfExists('customer_decision_evidence');
        Schema::dropIfExists('customer_decision_components');
        Schema::dropIfExists('customer_decision_cases');
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS customer_decision_case_truncate_guard();
DROP FUNCTION IF EXISTS customer_decision_case_guard();
DROP FUNCTION IF EXISTS customer_decision_append_only_guard();
DROP FUNCTION IF EXISTS customer_decision_stamp_recorded_at();
SQL);
    }
};
