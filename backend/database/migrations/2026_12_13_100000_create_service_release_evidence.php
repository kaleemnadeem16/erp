<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Batch 1 (Sales half, disabled): immutable customer-agreed completion conditions and immutable release evidence for a prepared service handover.
 *
 * The five preparation tables are append-only and constrained to PREPARED / version 1 / null completion conditions (2026_12_12_100000); they are NOT touched here. A
 * release is a NEW linked fact: the effective release status of a stage is derived from the presence of its `handover_release_events` row, never by updating the stage.
 * The Jobs receiver (a later migration) references `handover_release_events` by its company-composite key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_completion_bases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('issued_line_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->text('agreed_text');
            $t->unsignedBigInteger('evidence_id');
            $t->string('attestation_policy', 40)->default('AUTHORISED_ATTESTATION');
            $t->char('intent_digest', 64);
            $t->unsignedBigInteger('recorded_by');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'service_completion_bases_company_id_unique');
            $t->unique(['company_id', 'issued_line_id', 'id'], 'service_completion_bases_line_unique');
            $t->unique(['company_id', 'issued_line_id', 'version'], 'service_completion_bases_version_unique');
            $t->foreign(['company_id', 'issued_line_id'], 'service_completion_bases_line_fk')->references(['company_id', 'id'])->on('quotation_issued_lines');
            $t->foreign(['company_id', 'issued_line_id', 'predecessor_id'], 'service_completion_bases_predecessor_fk')->references(['company_id', 'issued_line_id', 'id'])->on('service_completion_bases');
            // `evidence_id` is a plain reference, NOT a foreign key: the intake storage must stay rollback-able on its own (issue #101, CustomerDecisionIntakeMigrationTest). The insert guard
            // below proves the row exists, is in this company and belongs to a case that accepted the line; the evidence table is append-only, so it cannot vanish afterwards.
            $t->foreign(['company_id', 'recorded_by'], 'service_completion_bases_recorder_fk')->references(['company_id', 'id'])->on('users');
        });
        // One successor per predecessor: the version chain never forks.
        DB::statement('CREATE UNIQUE INDEX service_completion_bases_successor_unique ON service_completion_bases (company_id, predecessor_id) WHERE predecessor_id IS NOT NULL');
        DB::statement("ALTER TABLE service_completion_bases ADD CONSTRAINT service_completion_bases_shape CHECK (
            version >= 1 AND ((version = 1 AND predecessor_id IS NULL) OR (version >= 2 AND predecessor_id IS NOT NULL))
            AND length(btrim(agreed_text)) BETWEEN 1 AND 4000 AND agreed_text = btrim(agreed_text)
            AND attestation_policy IN ('AUTHORISED_ATTESTATION') AND intent_digest ~ '^[0-9a-f]{64}\$'
        )");

        Schema::create('handover_release_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('stage_id');
            $t->unsignedBigInteger('baseline_id');
            $t->unsignedBigInteger('basis_id');
            $t->string('purpose', 40);
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 85);
            $t->char('intent_hash', 64);
            $t->string('condition_identity', 120);
            $t->unsignedInteger('condition_version');
            $t->boolean('cost_required');
            $t->string('cost_status', 20);
            $t->string('cost_policy_version', 40);
            $t->jsonb('relied_on');
            $t->timestampTz('released_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'handover_release_events_company_id_unique');
            // Business uniqueness: one release per stage and purpose, whatever key, actor or request identity arrives.
            $t->unique(['company_id', 'stage_id', 'purpose'], 'handover_release_events_stage_purpose_unique');
            // Intent identity: one outcome per actor and key.
            $t->unique(['company_id', 'actor_id', 'request_key'], 'handover_release_events_actor_key_unique');
            $t->foreign(['company_id', 'stage_id'], 'handover_release_events_stage_fk')->references(['company_id', 'id'])->on('handover_stages');
            $t->foreign(['company_id', 'baseline_id'], 'handover_release_events_baseline_fk')->references(['company_id', 'id'])->on('handover_baselines');
            $t->foreign(['company_id', 'basis_id'], 'handover_release_events_basis_fk')->references(['company_id', 'id'])->on('service_completion_bases');
            $t->foreign(['company_id', 'actor_id'], 'handover_release_events_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE handover_release_events ADD CONSTRAINT handover_release_events_shape CHECK (
            purpose IN ('SERVICE_JOB_RECEIPT') AND length(btrim(request_key)) > 0 AND intent_hash ~ '^[0-9a-f]{64}\$'
            AND length(btrim(condition_identity)) > 0 AND condition_version >= 1
            AND cost_status IN ('UNASSESSED','UNKNOWN','EVIDENCED')
            AND (NOT cost_required OR cost_status = 'EVIDENCED')
            AND jsonb_typeof(relied_on) = 'object'
        )");

        DB::unprepared(<<<'SQL'
-- A completion basis is genuine agreement evidence: it cites WRITTEN, authority-bearing consent evidence of a case that really accepted this issued line, and
-- it continues the line's version chain exactly (the next version, predecessor the current head). The recording time is the database's own clock.
CREATE OR REPLACE FUNCTION service_completion_basis_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ev record; head record; accepted bigint;
BEGIN
 SELECT * INTO ev FROM customer_decision_evidence WHERE company_id=NEW.company_id AND id=NEW.evidence_id;
 SELECT count(*) INTO accepted FROM customer_acceptance_lines al JOIN customer_acceptances a ON (a.company_id,a.id)=(al.company_id,al.acceptance_id)
  WHERE al.company_id=NEW.company_id AND al.issued_line_id=NEW.issued_line_id AND a.case_id=ev.case_id;
 IF ev.id IS NULL OR accepted=0 OR ev.source_kind='VERBAL' OR ev.authority_verification_basis='UNVERIFIED'
 THEN RAISE EXCEPTION 'A completion basis must cite written, authority-bearing evidence of the case that accepted this line' USING ERRCODE='23514'; END IF;
 SELECT * INTO head FROM service_completion_bases WHERE company_id=NEW.company_id AND issued_line_id=NEW.issued_line_id ORDER BY version DESC LIMIT 1;
 IF (head.id IS NULL AND (NEW.version<>1 OR NEW.predecessor_id IS NOT NULL)) OR (head.id IS NOT NULL AND (NEW.version<>head.version+1 OR NEW.predecessor_id IS DISTINCT FROM head.id))
 THEN RAISE EXCEPTION 'A completion basis must continue the version chain of its issued line' USING ERRCODE='23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER service_completion_basis_guard BEFORE INSERT ON service_completion_bases FOR EACH ROW EXECUTE FUNCTION service_completion_basis_guard();

-- A release binds one baseline of its own stage to the CURRENT head of the completion-basis chain of that baseline's accepted line, for a stage still prepared.
CREATE OR REPLACE FUNCTION handover_release_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE b record; s record; cb record; head_version integer;
BEGIN
 SELECT * INTO b FROM handover_baselines WHERE company_id=NEW.company_id AND id=NEW.baseline_id;
 SELECT * INTO s FROM handover_stages WHERE company_id=NEW.company_id AND id=NEW.stage_id;
 SELECT * INTO cb FROM service_completion_bases WHERE company_id=NEW.company_id AND id=NEW.basis_id;
 SELECT max(version) INTO head_version FROM service_completion_bases WHERE company_id=NEW.company_id AND issued_line_id=cb.issued_line_id;
 IF b.id IS NULL OR s.id IS NULL OR cb.id IS NULL OR b.stage_id<>NEW.stage_id OR cb.issued_line_id<>b.issued_line_id OR s.state<>'PREPARED'
  OR cb.version<>head_version OR NEW.condition_version<>cb.version
  OR NEW.condition_identity<>('completion-basis:'||cb.company_id||':'||cb.id||':v'||cb.version)
 THEN RAISE EXCEPTION 'A release must bind its own stage baseline to the current completion basis of the accepted line' USING ERRCODE='23514'; END IF;
 NEW.released_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER handover_release_event_guard BEFORE INSERT ON handover_release_events FOR EACH ROW EXECUTE FUNCTION handover_release_event_guard();
SQL);
        foreach (['service_completion_bases', 'handover_release_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard()");
            DB::statement("CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard()");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE handover_release_events, service_completion_bases IN ACCESS EXCLUSIVE MODE');
        foreach (['handover_release_events', 'service_completion_bases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Release or completion-basis evidence exists; separately approved backout required.');
            }
        }
        Schema::drop('handover_release_events');
        Schema::drop('service_completion_bases');
        DB::unprepared('DROP FUNCTION handover_release_event_guard(), service_completion_basis_guard()');
    }
};
