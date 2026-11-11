<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inactive pending preparation; no target or release identity exists in these tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('acceptance_id');
            $t->unsignedBigInteger('prepared_by');
            $t->string('path', 30);
            $t->string('policy_version', 40);
            $t->char('intent_digest', 64);
            $t->text('preview_token');
            $t->jsonb('inputs');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'acceptance_id']);
            $t->foreign(['company_id', 'acceptance_id'])->references(['company_id', 'id'])->on('customer_acceptances');
            $t->foreign(['company_id', 'prepared_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE handover_plans ADD CONSTRAINT handover_plan_shape CHECK (path='SERVICE_JOB' AND policy_version='SERVICE_PREPARATION.1' AND length(preview_token)>0 AND jsonb_typeof(inputs)='object')");
        Schema::create('handover_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('acceptance_line_id');
            $t->unsignedSmallInteger('full_scope_units')->default(1);
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'plan_id']);
            $t->unique(['company_id', 'acceptance_line_id']);
            $t->foreign(['company_id', 'plan_id'])->references(['company_id', 'id'])->on('handover_plans');
            $t->foreign(['company_id', 'acceptance_line_id'])->references(['company_id', 'id'])->on('customer_acceptance_lines');
        });
        DB::statement('ALTER TABLE handover_allocations ADD CONSTRAINT handover_allocation_full CHECK (full_scope_units=1)');
        Schema::create('handover_stages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('allocation_id');
            $t->string('stage_key', 30)->default('INITIAL');
            $t->string('target_kind', 30)->default('SERVICE_JOB');
            $t->string('state', 20)->default('PREPARED');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'allocation_id']);
            $t->foreign(['company_id', 'allocation_id'])->references(['company_id', 'id'])->on('handover_allocations');
        });
        DB::statement("ALTER TABLE handover_stages ADD CONSTRAINT handover_stage_initial CHECK (stage_key='INITIAL' AND target_kind='SERVICE_JOB' AND state='PREPARED')");
        Schema::create('handover_baselines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('stage_id');
            $t->unsignedBigInteger('issued_line_id');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('site_id')->nullable();
            $t->jsonb('operational_snapshot');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'stage_id']);
            $t->foreign(['company_id', 'stage_id'])->references(['company_id', 'id'])->on('handover_stages');
            $t->foreign(['company_id', 'issued_line_id'])->references(['company_id', 'id'])->on('quotation_issued_lines');
            // Party children scope through their parent; the insert guard also proves equality to the acceptance's Party.
            $t->foreign('contact_id')->references('id')->on('party_contacts');
            $t->foreign('site_id')->references('id')->on('party_sites');
        });
        DB::statement("ALTER TABLE handover_baselines ADD CONSTRAINT handover_baseline_shape CHECK (version=1 AND jsonb_typeof(operational_snapshot)='object' AND jsonb_exists_all(operational_snapshot, ARRAY['deliverable_identity','description','measure_id','quantity','completion_conditions','location_kind','instructions','contact','site']) AND (operational_snapshot - ARRAY['deliverable_identity','description','measure_id','quantity','completion_conditions','location_kind','instructions','contact','site'])='{}'::jsonb AND operational_snapshot->'measure_id'='null'::jsonb AND operational_snapshot->'quantity'='null'::jsonb AND operational_snapshot->'completion_conditions'='null'::jsonb)");
        Schema::create('handover_readiness_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('stage_id');
            $t->jsonb('assessment');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'stage_id']);
            $t->foreign(['company_id', 'stage_id'])->references(['company_id', 'id'])->on('handover_stages');
        });
        DB::statement("ALTER TABLE handover_readiness_evidence ADD CONSTRAINT handover_readiness_pending CHECK (jsonb_exists_all(assessment, ARRAY['status','assessment_version','missing','preparation_ready','next_actions']) AND jsonb_typeof(assessment->'preparation_ready')='boolean' AND jsonb_typeof(assessment->'next_actions')='array' AND assessment->>'status'='NOT_READY' AND assessment->>'assessment_version'='SERVICE_PREPARATION.1' AND jsonb_typeof(assessment->'missing')='array' AND jsonb_array_length(assessment->'missing')>0)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION handover_preparation_parent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a record; l record; party bigint; expected_line bigint;
BEGIN
 IF TG_TABLE_NAME='handover_allocations' THEN
  SELECT * INTO a FROM handover_plans WHERE company_id=NEW.company_id AND id=NEW.plan_id;
  SELECT * INTO l FROM customer_acceptance_lines WHERE company_id=NEW.company_id AND id=NEW.acceptance_line_id;
  IF a.id IS NULL OR l.id IS NULL OR a.acceptance_id<>l.acceptance_id THEN RAISE EXCEPTION 'Allocation must use its plan acceptance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='handover_baselines' THEN
  SELECT al.issued_line_id, c.party_id INTO expected_line, party FROM handover_stages s
   JOIN handover_allocations ha ON (ha.company_id,ha.id)=(s.company_id,s.allocation_id)
   JOIN customer_acceptance_lines al ON (al.company_id,al.id)=(ha.company_id,ha.acceptance_line_id)
   JOIN customer_acceptances ca ON (ca.company_id,ca.id)=(al.company_id,al.acceptance_id)
   JOIN customer_decision_cases c ON (c.company_id,c.id)=(ca.company_id,ca.case_id)
   WHERE s.company_id=NEW.company_id AND s.id=NEW.stage_id;
  SELECT * INTO l FROM quotation_issued_lines WHERE company_id=NEW.company_id AND id=NEW.issued_line_id;
  IF expected_line IS NULL OR expected_line<>NEW.issued_line_id OR l.source_kind<>'SERVICE_ITEM'
   OR NEW.operational_snapshot->>'description' IS DISTINCT FROM l.description
   OR NEW.operational_snapshot->>'deliverable_identity' IS DISTINCT FROM ('issued-scope:'||l.logical_scope_id)
   OR (NEW.contact_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM party_contacts WHERE id=NEW.contact_id AND party_id=party))
   OR (NEW.site_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM party_sites WHERE id=NEW.site_id AND party_id=party))
  THEN RAISE EXCEPTION 'Baseline needs exact accepted service scope and same-Party operational references' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER handover_allocation_parent BEFORE INSERT ON handover_allocations FOR EACH ROW EXECUTE FUNCTION handover_preparation_parent_guard();
CREATE TRIGGER handover_baseline_parent BEFORE INSERT ON handover_baselines FOR EACH ROW EXECUTE FUNCTION handover_preparation_parent_guard();

CREATE OR REPLACE FUNCTION handover_preparation_complete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM handover_allocations a JOIN handover_stages s ON (s.company_id,s.allocation_id)=(a.company_id,a.id)
  JOIN handover_baselines b ON (b.company_id,b.stage_id)=(s.company_id,s.id)
  JOIN handover_readiness_evidence r ON (r.company_id,r.stage_id)=(s.company_id,s.id)
  WHERE a.company_id=NEW.company_id AND a.plan_id=NEW.id)
 THEN RAISE EXCEPTION 'Plan, full allocation, initial stage, baseline and readiness must commit together' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER handover_preparation_complete AFTER INSERT ON handover_plans DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_preparation_complete_guard();
SQL);
        foreach (['handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard()");
            DB::statement("CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard()");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE handover_readiness_evidence, handover_baselines, handover_stages, handover_allocations, handover_plans IN ACCESS EXCLUSIVE MODE');
        foreach (['handover_readiness_evidence', 'handover_baselines', 'handover_stages', 'handover_allocations', 'handover_plans'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Handover preparation evidence exists; separately approved backout required.');
            }
        }
        foreach (['handover_readiness_evidence', 'handover_baselines', 'handover_stages', 'handover_allocations', 'handover_plans'] as $table) {
            Schema::drop($table);
        }
        DB::unprepared('DROP FUNCTION handover_preparation_complete_guard(), handover_preparation_parent_guard()');
    }
};
