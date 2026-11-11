<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FULL_ONLY acceptance storage; no grants, activation, partial entitlement or handover. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_evidence_policy_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('policy_code', 40);
            $t->jsonb('source_requirements');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'version']);
        });
        DB::statement("ALTER TABLE customer_evidence_policy_versions ADD CONSTRAINT customer_evidence_policy_shape CHECK (version = 1 AND policy_code = 'WRITTEN_VERIFIED_FULL_ONLY_V1' AND source_requirements = '{\"verbal\":false,\"verification\":[\"DOCUMENT_ON_FILE\",\"CONFIRMED_BY_SELLER\"]}'::jsonb)");
        Schema::create('customer_scope_claim_heads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('scope_anchor_id')->nullable();
            $t->unsignedBigInteger('choice_group_id')->nullable();
            $t->unsignedBigInteger('generation')->default(0);
            $t->unsignedSmallInteger('capacity')->default(1);
            $t->unsignedSmallInteger('consumed')->default(0);
            $t->unsignedBigInteger('selected_scope_id')->nullable();
            $t->string('baseline_label', 30)->default('BASELINE_CAPTURED');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'scope_anchor_id']);
            $t->unique(['company_id', 'choice_group_id']);
            foreach (['scope_anchor_id', 'selected_scope_id'] as $column) {
                $t->foreign(['company_id', 'family_id', $column])->references(['company_id', 'family_id', 'id'])->on('quotation_logical_scopes');
            }
            $t->foreign(['company_id', 'family_id', 'choice_group_id'])->references(['company_id', 'family_id', 'id'])->on('quotation_choice_groups');
        });
        DB::statement("ALTER TABLE customer_scope_claim_heads ADD CONSTRAINT customer_claim_head_shape CHECK (num_nonnulls(scope_anchor_id, choice_group_id) = 1 AND capacity = 1 AND consumed BETWEEN 0 AND capacity AND generation = consumed AND ((consumed = 0 AND selected_scope_id IS NULL) OR (consumed = 1 AND selected_scope_id IS NOT NULL)) AND baseline_label = 'BASELINE_CAPTURED')");
        Schema::create('customer_acceptances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('decision_component_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('consent_evidence_id');
            $t->unsignedBigInteger('policy_version_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('intent_digest', 64);
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'decision_component_id']);
            $t->foreign(['company_id', 'case_id', 'decision_component_id'], 'customer_acceptance_component_fk')->references(['company_id', 'case_id', 'id'])->on('customer_decision_components');
            $t->foreign(['company_id', 'case_id', 'consent_evidence_id'], 'customer_acceptance_evidence_fk')->references(['company_id', 'case_id', 'id'])->on('customer_decision_evidence');
            $t->foreign(['company_id', 'revision_id'])->references(['company_id', 'revision_id'])->on('quotation_issues');
            $t->foreign(['company_id', 'policy_version_id'])->references(['company_id', 'id'])->on('customer_evidence_policy_versions');
            $t->foreign(['company_id', 'actor_id'])->references(['company_id', 'id'])->on('users');
        });
        // Frozen commercial terms remain in the immutable issued line, avoiding a second monetary ledger or invented FX/base amounts.
        Schema::create('customer_acceptance_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('acceptance_id');
            $t->unsignedBigInteger('issued_line_id');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'acceptance_id']);
            $t->unique(['company_id', 'issued_line_id']);
            $t->foreign(['company_id', 'acceptance_id'])->references(['company_id', 'id'])->on('customer_acceptances');
            $t->foreign(['company_id', 'issued_line_id'])->references(['company_id', 'id'])->on('quotation_issued_lines');
        });
        Schema::create('customer_claim_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('acceptance_id');
            $t->unsignedBigInteger('claim_head_id');
            $t->unsignedSmallInteger('sequence')->default(1);
            $t->unsignedSmallInteger('quantity_delta')->default(1);
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'claim_head_id', 'sequence']);
            $t->foreign(['company_id', 'acceptance_id'])->references(['company_id', 'id'])->on('customer_acceptances');
            $t->foreign(['company_id', 'claim_head_id'])->references(['company_id', 'id'])->on('customer_scope_claim_heads');
        });
        DB::statement('ALTER TABLE customer_claim_events ADD CONSTRAINT customer_claim_event_shape CHECK (sequence = 1 AND quantity_delta = 1)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION customer_acceptance_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c record; r record; e record;
BEGIN
 SELECT d.*, k.party_id INTO c FROM customer_decision_components d JOIN customer_decision_cases k ON (k.company_id,k.id)=(d.company_id,d.case_id) WHERE d.company_id=NEW.company_id AND d.id=NEW.decision_component_id;
 SELECT * INTO r FROM quotation_revisions WHERE company_id=NEW.company_id AND id=NEW.revision_id FOR UPDATE;
 SELECT * INTO e FROM customer_decision_evidence WHERE company_id=NEW.company_id AND id=NEW.consent_evidence_id;
 IF c.id IS NULL OR r.id IS NULL OR c.family_id <> r.family_id OR c.party_id <> r.recipient_party_id
 OR r.state <> 'ISSUED' OR r.expires_at <= clock_timestamp()
 OR EXISTS (SELECT 1 FROM quotation_dispositions WHERE company_id=NEW.company_id AND revision_id=r.id)
 OR e.id IS NULL OR e.case_id <> c.case_id OR e.source_kind='VERBAL' OR e.authority_verification_basis NOT IN ('DOCUMENT_ON_FILE','CONFIRMED_BY_SELLER')
 OR e.customer_representative_snapshot IS NULL
 OR e.id <> (SELECT max(id) FROM customer_decision_evidence WHERE company_id=NEW.company_id AND case_id=c.case_id)
 THEN RAISE EXCEPTION 'Acceptance needs current verified consent for an available issued offer of the same customer and family' USING ERRCODE='23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER customer_acceptance_identity BEFORE INSERT ON customer_acceptances FOR EACH ROW EXECUTE FUNCTION customer_acceptance_identity_guard();

CREATE OR REPLACE FUNCTION customer_claim_head_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.consumed <> 0 OR NEW.generation <> 0 OR NEW.selected_scope_id IS NOT NULL THEN RAISE EXCEPTION 'A baseline invents no consent' USING ERRCODE='23514'; END IF;
  IF NEW.scope_anchor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM quotation_logical_scopes WHERE company_id=NEW.company_id AND family_id=NEW.family_id AND id=NEW.scope_anchor_id AND overlap_anchor_id IS NULL) THEN RAISE EXCEPTION 'A scope head names an original anchor' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP='DELETE' OR pg_trigger_depth() < 2 THEN RAISE EXCEPTION 'Claim heads change only through claim events' USING ERRCODE='23514'; END IF;
 IF (NEW.company_id,NEW.family_id,NEW.scope_anchor_id,NEW.choice_group_id,NEW.capacity,NEW.baseline_label) IS DISTINCT FROM (OLD.company_id,OLD.family_id,OLD.scope_anchor_id,OLD.choice_group_id,OLD.capacity,OLD.baseline_label) THEN RAISE EXCEPTION 'Claim identity is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER customer_claim_head_write BEFORE INSERT OR UPDATE OR DELETE ON customer_scope_claim_heads FOR EACH ROW EXECUTE FUNCTION customer_claim_head_guard();

CREATE OR REPLACE FUNCTION customer_claim_event_apply() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE h record; l record; c record;
BEGIN
 SELECT * INTO h FROM customer_scope_claim_heads WHERE company_id=NEW.company_id AND id=NEW.claim_head_id FOR UPDATE;
 SELECT i.* INTO l FROM customer_acceptance_lines al JOIN quotation_issued_lines i ON (i.company_id,i.id)=(al.company_id,al.issued_line_id) WHERE al.company_id=NEW.company_id AND al.acceptance_id=NEW.acceptance_id;
 SELECT d.* INTO c FROM customer_acceptances a JOIN customer_decision_components d ON (d.company_id,d.id)=(a.company_id,a.decision_component_id) WHERE a.company_id=NEW.company_id AND a.id=NEW.acceptance_id;
 IF h.id IS NULL OR l.id IS NULL OR c.id IS NULL OR h.family_id <> l.family_id OR h.consumed <> 0
 OR (h.scope_anchor_id IS NOT NULL AND h.scope_anchor_id <> c.original_scope_anchor_id)
 OR (h.choice_group_id IS NOT NULL AND h.choice_group_id IS DISTINCT FROM l.choice_group_id)
 THEN RAISE EXCEPTION 'Claim is unavailable or unrelated to the accepted scope' USING ERRCODE='23514'; END IF;
 UPDATE customer_scope_claim_heads SET consumed=1,generation=1,selected_scope_id=l.logical_scope_id WHERE company_id=NEW.company_id AND id=h.id;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER customer_claim_event_insert BEFORE INSERT ON customer_claim_events FOR EACH ROW EXECUTE FUNCTION customer_claim_event_apply();

CREATE OR REPLACE FUNCTION customer_acceptance_complete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a record; c record; l record; expected_claims integer;
BEGIN
 SELECT * INTO a FROM customer_acceptances WHERE company_id=NEW.company_id AND id=NEW.id;
 SELECT * INTO c FROM customer_decision_components WHERE company_id=a.company_id AND id=a.decision_component_id;
 SELECT i.* INTO l FROM customer_acceptance_lines al JOIN quotation_issued_lines i ON (i.company_id,i.id)=(al.company_id,al.issued_line_id) WHERE al.company_id=a.company_id AND al.acceptance_id=a.id;
 IF l.id IS NULL OR l.revision_id <> a.revision_id OR NOT EXISTS (SELECT 1 FROM quotation_logical_scopes s WHERE s.company_id=a.company_id AND s.id=l.logical_scope_id AND coalesce(s.overlap_anchor_id,s.id)=c.original_scope_anchor_id)
 THEN RAISE EXCEPTION 'Acceptance requires one full issued scope of the original lineage' USING ERRCODE='23514'; END IF;
 expected_claims := CASE WHEN l.choice_group_id IS NULL THEN 1 ELSE 2 END;
 IF (SELECT count(*) FROM customer_claim_events WHERE company_id=a.company_id AND acceptance_id=a.id) <> expected_claims
 OR NOT EXISTS (SELECT 1 FROM customer_claim_events e JOIN customer_scope_claim_heads h ON (h.company_id,h.id)=(e.company_id,e.claim_head_id) WHERE e.company_id=a.company_id AND e.acceptance_id=a.id AND h.scope_anchor_id=c.original_scope_anchor_id)
 THEN RAISE EXCEPTION 'Acceptance and all root/choice claims commit together' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER customer_acceptance_complete AFTER INSERT ON customer_acceptances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION customer_acceptance_complete_guard();
SQL);
        foreach (['customer_evidence_policy_versions', 'customer_acceptances', 'customer_acceptance_lines', 'customer_claim_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard()");
            DB::statement("CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard()");
        }
        DB::statement('CREATE TRIGGER customer_scope_claim_heads_truncate BEFORE TRUNCATE ON customer_scope_claim_heads FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard()');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE customer_claim_events, customer_acceptance_lines, customer_acceptances, customer_scope_claim_heads, customer_evidence_policy_versions IN ACCESS EXCLUSIVE MODE');
        foreach (['customer_claim_events', 'customer_acceptance_lines', 'customer_acceptances', 'customer_scope_claim_heads', 'customer_evidence_policy_versions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Acceptance evidence or baselines exist; a separately approved backout is required.');
            }
        }
        foreach (['customer_claim_events', 'customer_acceptance_lines', 'customer_acceptances', 'customer_scope_claim_heads', 'customer_evidence_policy_versions'] as $table) {
            Schema::drop($table);
        }
        DB::unprepared('DROP FUNCTION customer_acceptance_complete_guard(), customer_claim_event_apply(), customer_claim_head_guard(), customer_acceptance_identity_guard()');
    }
};
