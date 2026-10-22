<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5, SP0b (docs/phases/phase-5-sp0a-design.md and -delivery-contract.md): the disabled Jobs foundation — four company-scoped tables and nothing that can
 * create or change a real Job. The Laravel queue table `jobs` is untouched; the domain parent is `service_jobs`.
 *
 *  - SERVICE_JOBS is the parent: code, a four-value state and a positive generation. It points at no origin, baseline or event, so there is no circular FK.
 *  - JOB_HANDOVER_ORIGINS is the future receiver receipt. It points AT the Job (composite company/job FK; the Job row is inserted first), one origin per Job and
 *    one per (purpose, business identity). It carries no acceptance, allocation, stage or source-id strings: those become real composite FKs when the Sales
 *    tables are a delivered dependency, and the identity gains its stage then.
 *  - JOB_BASELINES declare the scope basis (a description and a completion-rule version) without inventing a measured unit; versions chain by a same-Job FK.
 *  - JOB_EVENTS record state/generation changes by sequence and generation. Uniqueness alone is claimed here, not the whole state machine.
 *  - Origins, baselines and events are history: a Jobs-specific guard refuses every update and delete. Rolling back refuses while any row exists.
 *
 * No duty, permission, route, grant or entry point accompanies these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_jobs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('code', 40);
            $t->string('state', 12);
            $t->unsignedInteger('generation')->default(1);
            $t->timestampsTz();
            $t->unique(['company_id', 'id'], 'service_jobs_company_id_unique');
            $t->unique(['company_id', 'code'], 'service_jobs_code_unique');
            $t->index(['company_id', 'state', 'id'], 'service_jobs_state_index');
        });
        DB::statement("ALTER TABLE service_jobs ADD CONSTRAINT service_jobs_shape CHECK (
            state IN ('RELEASED','ACTIVE','COMPLETED','CLOSED') AND generation >= 1 AND length(trim(code)) > 0
        )");

        Schema::create('job_handover_origins', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->string('source_contract_version', 40);
            $t->string('target_purpose', 60);
            $t->string('business_identity', 120);
            $t->char('intent_hash', 64);
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_handover_origins_company_id_unique');
            $t->unique(['company_id', 'job_id'], 'job_handover_origins_job_unique');
            $t->unique(['company_id', 'target_purpose', 'business_identity'], 'job_handover_origins_identity_unique');
            $t->foreign(['company_id', 'job_id'], 'job_handover_origins_job_fk')->references(['company_id', 'id'])->on('service_jobs');
        });
        DB::statement("ALTER TABLE job_handover_origins ADD CONSTRAINT job_handover_origins_shape CHECK (
            length(trim(source_contract_version)) > 0 AND length(trim(target_purpose)) > 0 AND length(trim(business_identity)) > 0 AND intent_hash ~ '^[0-9a-f]{64}$'
        )");

        Schema::create('job_baselines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->string('fingerprint', 128);
            $t->text('scope_description');
            $t->string('completion_rule_version', 40);
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_baselines_company_id_unique');
            $t->unique(['company_id', 'job_id', 'id'], 'job_baselines_job_unique');
            $t->unique(['company_id', 'job_id', 'version'], 'job_baselines_version_unique');
            $t->foreign(['company_id', 'job_id'], 'job_baselines_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'job_id', 'predecessor_id'], 'job_baselines_predecessor_fk')->references(['company_id', 'job_id', 'id'])->on('job_baselines');
        });
        DB::statement('ALTER TABLE job_baselines ADD CONSTRAINT job_baselines_shape CHECK (
            version >= 1 AND (predecessor_id IS NULL OR version >= 2) AND length(trim(fingerprint)) > 0
            AND length(trim(scope_description)) > 0 AND length(trim(completion_rule_version)) > 0
        )');

        Schema::create('job_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedInteger('sequence');
            $t->string('operation', 60);
            $t->string('from_state', 12)->nullable();
            $t->string('to_state', 12);
            $t->unsignedInteger('prior_generation');
            $t->unsignedInteger('next_generation');
            $t->timestampTz('occurred_at')->nullable();
            $t->string('occurrence_precision', 20);
            $t->timestampTz('recorded_at');
            $t->unsignedBigInteger('actor_id');
            $t->text('reason')->nullable();
            $t->unsignedInteger('evidence_version');
            $t->unique(['company_id', 'id'], 'job_events_company_id_unique');
            $t->unique(['company_id', 'job_id', 'sequence'], 'job_events_sequence_unique');
            $t->unique(['company_id', 'job_id', 'next_generation'], 'job_events_generation_unique');
            $t->foreign(['company_id', 'job_id'], 'job_events_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'actor_id'], 'job_events_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE job_events ADD CONSTRAINT job_events_shape CHECK (
            sequence >= 1 AND next_generation >= 1 AND evidence_version >= 1
            AND length(trim(operation)) > 0 AND length(trim(occurrence_precision)) > 0
            AND to_state IN ('RELEASED','ACTIVE','COMPLETED','CLOSED') AND (from_state IS NULL OR from_state IN ('RELEASED','ACTIVE','COMPLETED','CLOSED'))
            AND (reason IS NULL OR length(trim(reason)) > 0)
        )");

        DB::unprepared(<<<'SQL'
-- Origins, baselines and events are evidence: never updated, never deleted. Deliberately not shared with the Documents guard.
CREATE OR REPLACE FUNCTION job_evidence_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER job_handover_origins_guard BEFORE UPDATE OR DELETE ON job_handover_origins FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_baselines_guard BEFORE UPDATE OR DELETE ON job_baselines FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_events_guard BEFORE UPDATE OR DELETE ON job_events FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase Job evidence — even fixtures: a populated rollback must be planned, not run.
        foreach (['job_handover_origins', 'job_baselines', 'job_events', 'service_jobs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has rows; rolling this migration back would erase Job evidence. Plan a backout first.");
            }
        }
        DB::statement('DROP TRIGGER IF EXISTS job_events_guard ON job_events');
        DB::statement('DROP TRIGGER IF EXISTS job_baselines_guard ON job_baselines');
        DB::statement('DROP TRIGGER IF EXISTS job_handover_origins_guard ON job_handover_origins');
        DB::statement('DROP FUNCTION IF EXISTS job_evidence_append_only_guard()');
        Schema::dropIfExists('job_events');
        Schema::dropIfExists('job_baselines');
        Schema::dropIfExists('job_handover_origins');
        Schema::dropIfExists('service_jobs');
    }
};
