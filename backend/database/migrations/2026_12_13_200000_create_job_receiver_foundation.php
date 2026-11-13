<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Batch 1 (Jobs half, disabled): the storage a Sales-released service lands in. Nothing here creates a Job by itself: the only writer is the internal
 * `ReceiveReleasedService` receiver, which runs inside the Sales release transaction and refuses unless `job.receive` is held (and no duty is ACTIVE).
 *
 *  - JOB_SALES_ORIGINS is the Sales-specific lineage of a Job, kept OUT of the generic `job_handover_origins` (which stays source-neutral): one Job per release event and one
 *    release event per Job, by company-composite keys into `job_handover_origins` and `handover_release_events`. A future origin (maintenance, warranty) adds its own table.
 *  - JOB_BASELINE_COMPONENTS is the immutable accepted deliverable and the exact agreed completion conditions the Job was released with, bound to its baseline, release event,
 *    allocation, issued line and completion basis; a guard proves they are the same facts the release event carries.
 *  - JOB_ACTIVITIES holds the one DEFAULT activity every basic Job has (no template, labour or percentage).
 * Every table is history (the existing Jobs guard refuses UPDATE, DELETE and TRUNCATE). A deferred constraint refuses, at COMMIT, a Sales Job without its baseline v1, its
 * component, its default activity and its RELEASE event. Rolling back refuses while any row exists, checked after the tables are locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_sales_origins', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('release_event_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_sales_origins_company_id_unique');
            $t->unique(['company_id', 'job_id'], 'job_sales_origins_job_unique');
            $t->unique(['company_id', 'job_id', 'release_event_id'], 'job_sales_origins_job_release_unique');
            // One Job per release event, whatever key, actor or retry produced it.
            $t->unique(['company_id', 'release_event_id'], 'job_sales_origins_release_unique');
            $t->foreign(['company_id', 'job_id'], 'job_sales_origins_origin_fk')->references(['company_id', 'job_id'])->on('job_handover_origins');
            $t->foreign(['company_id', 'release_event_id'], 'job_sales_origins_release_fk')->references(['company_id', 'id'])->on('handover_release_events');
        });

        Schema::create('job_baseline_components', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('baseline_id');
            $t->unsignedBigInteger('release_event_id');
            $t->unsignedBigInteger('allocation_id');
            $t->unsignedBigInteger('issued_line_id');
            $t->unsignedBigInteger('basis_id');
            $t->string('deliverable_identity', 120);
            $t->text('deliverable_text');
            $t->text('agreed_conditions');
            $t->string('condition_identity', 120);
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_baseline_components_company_id_unique');
            $t->unique(['company_id', 'baseline_id'], 'job_baseline_components_baseline_unique');
            $t->foreign(['company_id', 'job_id', 'baseline_id'], 'job_baseline_components_baseline_fk')->references(['company_id', 'job_id', 'id'])->on('job_baselines');
            $t->foreign(['company_id', 'job_id', 'release_event_id'], 'job_baseline_components_origin_fk')->references(['company_id', 'job_id', 'release_event_id'])->on('job_sales_origins');
            $t->foreign(['company_id', 'allocation_id'], 'job_baseline_components_allocation_fk')->references(['company_id', 'id'])->on('handover_allocations');
            $t->foreign(['company_id', 'issued_line_id'], 'job_baseline_components_line_fk')->references(['company_id', 'id'])->on('quotation_issued_lines');
            $t->foreign(['company_id', 'basis_id'], 'job_baseline_components_basis_fk')->references(['company_id', 'id'])->on('service_completion_bases');
        });
        DB::statement('ALTER TABLE job_baseline_components ADD CONSTRAINT job_baseline_components_shape CHECK (
            length(btrim(deliverable_identity)) > 0 AND length(btrim(deliverable_text)) > 0 AND length(btrim(agreed_conditions)) > 0 AND length(btrim(condition_identity)) > 0
        )');

        Schema::create('job_activities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->string('kind', 12);
            $t->text('description');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_activities_company_id_unique');
            // Exactly one default activity per Job (the only kind the basic workflow knows).
            $t->unique(['company_id', 'job_id', 'kind'], 'job_activities_kind_unique');
            $t->foreign(['company_id', 'job_id'], 'job_activities_job_fk')->references(['company_id', 'id'])->on('service_jobs');
        });
        DB::statement("ALTER TABLE job_activities ADD CONSTRAINT job_activities_shape CHECK (kind = 'DEFAULT' AND length(btrim(description)) > 0)");

        DB::unprepared(<<<'SQL'
-- The lineage must be the release it names: same purpose, same business identity (the stage), same intent as the generic origin row of the same Job.
CREATE OR REPLACE FUNCTION job_sales_origin_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o job_handover_origins%ROWTYPE; e handover_release_events%ROWTYPE;
BEGIN
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 SELECT * INTO o FROM job_handover_origins WHERE company_id = NEW.company_id AND job_id = NEW.job_id;
 SELECT * INTO e FROM handover_release_events WHERE company_id = NEW.company_id AND id = NEW.release_event_id;
 IF o.id IS NULL OR e.id IS NULL OR o.target_purpose <> e.purpose OR o.business_identity <> ('stage:' || e.stage_id) OR o.intent_hash <> e.intent_hash
 THEN RAISE EXCEPTION 'A Sales Job origin must be the generic origin of the same Job for the release it names' USING ERRCODE = '23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER job_sales_origins_guard_v1 BEFORE INSERT ON job_sales_origins FOR EACH ROW EXECUTE FUNCTION job_sales_origin_guard_v1();

-- A component is exactly what its release event carries: the same baseline-bound issued line, allocation, completion basis and agreed text, on baseline version 1.
CREATE OR REPLACE FUNCTION job_baseline_component_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e handover_release_events%ROWTYPE; hb handover_baselines%ROWTYPE; st handover_stages%ROWTYPE; cb service_completion_bases%ROWTYPE; il quotation_issued_lines%ROWTYPE; jb job_baselines%ROWTYPE;
BEGIN
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 SELECT * INTO e FROM handover_release_events WHERE company_id = NEW.company_id AND id = NEW.release_event_id;
 SELECT * INTO hb FROM handover_baselines WHERE company_id = NEW.company_id AND id = e.baseline_id;
 SELECT * INTO st FROM handover_stages WHERE company_id = NEW.company_id AND id = e.stage_id;
 SELECT * INTO cb FROM service_completion_bases WHERE company_id = NEW.company_id AND id = e.basis_id;
 SELECT * INTO il FROM quotation_issued_lines WHERE company_id = NEW.company_id AND id = hb.issued_line_id;
 SELECT * INTO jb FROM job_baselines WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.baseline_id;
 IF e.id IS NULL OR hb.id IS NULL OR st.id IS NULL OR cb.id IS NULL OR il.id IS NULL OR jb.id IS NULL OR jb.version <> 1
  OR NEW.basis_id <> cb.id OR NEW.issued_line_id <> hb.issued_line_id OR NEW.allocation_id <> st.allocation_id
  OR NEW.condition_identity <> e.condition_identity OR NEW.agreed_conditions <> cb.agreed_text
  OR NEW.deliverable_text <> il.description OR NEW.deliverable_identity <> (hb.operational_snapshot->>'deliverable_identity')
 THEN RAISE EXCEPTION 'A Job baseline component must carry exactly the facts of the release event it names' USING ERRCODE = '23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER job_baseline_components_guard_v1 BEFORE INSERT ON job_baseline_components FOR EACH ROW EXECUTE FUNCTION job_baseline_component_guard_v1();

CREATE OR REPLACE FUNCTION job_activity_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER job_activities_guard_v1 BEFORE INSERT ON job_activities FOR EACH ROW EXECUTE FUNCTION job_activity_guard_v1();

-- COMMIT-time completeness: a Sales Job never commits without its RELEASE event, baseline v1, exactly one component bound to its release and its default activity.
CREATE OR REPLACE FUNCTION job_sales_origin_consistency_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_company bigint := NEW.company_id; v_job bigint := NEW.job_id; v_release bigint; n int;
BEGIN
 SELECT release_event_id INTO v_release FROM job_sales_origins WHERE company_id = v_company AND job_id = v_job;
 IF v_release IS NULL THEN
  RAISE EXCEPTION 'Job % has a baseline component but no Sales origin', v_job USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM job_events WHERE company_id = v_company AND job_id = v_job AND sequence = 1 AND operation = 'RELEASE') THEN
  RAISE EXCEPTION 'Sales Job % has no RELEASE event', v_job USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM job_baselines WHERE company_id = v_company AND job_id = v_job AND version = 1) THEN
  RAISE EXCEPTION 'Sales Job % has no baseline version 1', v_job USING ERRCODE = '23514';
 END IF;
 SELECT count(*) INTO n FROM job_baseline_components WHERE company_id = v_company AND job_id = v_job AND release_event_id = v_release;
 IF n <> 1 THEN
  RAISE EXCEPTION 'Sales Job % must have exactly one baseline component (found %)', v_job, n USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM job_activities WHERE company_id = v_company AND job_id = v_job AND kind = 'DEFAULT') THEN
  RAISE EXCEPTION 'Sales Job % has no default activity', v_job USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER job_sales_origins_consistency AFTER INSERT ON job_sales_origins DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_sales_origin_consistency_v1();
CREATE CONSTRAINT TRIGGER job_baseline_components_consistency AFTER INSERT ON job_baseline_components DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_sales_origin_consistency_v1();

-- History: the existing Jobs guard refuses UPDATE, DELETE and TRUNCATE.
CREATE TRIGGER job_sales_origins_guard BEFORE UPDATE OR DELETE ON job_sales_origins FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_baseline_components_guard BEFORE UPDATE OR DELETE ON job_baseline_components FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_activities_guard BEFORE UPDATE OR DELETE ON job_activities FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_sales_origins_truncate_guard BEFORE TRUNCATE ON job_sales_origins FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_baseline_components_truncate_guard BEFORE TRUNCATE ON job_baseline_components FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_activities_truncate_guard BEFORE TRUNCATE ON job_activities FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: nothing can write evidence between the check and the drop.
        DB::statement('LOCK TABLE job_activities, job_baseline_components, job_sales_origins IN ACCESS EXCLUSIVE MODE');
        foreach (['job_activities', 'job_baseline_components', 'job_sales_origins'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has rows; rolling this migration back would erase released Job evidence. Plan a backout first.");
            }
        }
        Schema::drop('job_activities');
        Schema::drop('job_baseline_components');
        Schema::drop('job_sales_origins');
        DB::unprepared('DROP FUNCTION job_sales_origin_consistency_v1(), job_activity_guard_v1(), job_baseline_component_guard_v1(), job_sales_origin_guard_v1()');
    }
};
