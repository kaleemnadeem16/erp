<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — internal Job completion and closeout (disabled; no route, no grant). ADDITIVE on the lifecycle foundation, never weakening it:
 *
 *  - job_completions: ONE immutable completion per Job — who, when, the completion statement (equal to its COMPLETE event's reason) and the original customer-agreed work it
 *    attests (the baseline, the Sales release event and the agreed-conditions identity and fingerprint the Job was released with).
 *  - job_closeout_assessments: immutable READY/BLOCKED assessments of a completed Job at one generation, idempotent per actor and request key.
 *  - job_closeouts: ONE immutable closeout per Job, consuming a READY assessment of exactly the generation it closes. Closing settles no invoice or balance.
 *  - The two lifecycle functions are PATCHED in place (the operation allowlist gains COMPLETE and CLOSE; the state-transition rule gains ACTIVE -> COMPLETED and
 *    COMPLETED -> CLOSED). Every other rule, trigger and guard is untouched, so a hold, assignment or start on a completed Job is still refused by the database.
 *  - COMMIT-time completeness (deferred constraint triggers): a COMPLETE event never commits without its completion row (agreeing with the event and the original agreed
 *    work, no unresolved hold), a CLOSE never without its closeout row and a READY assessment of the generation it closes.
 *
 * Rolling back refuses while any completion, assessment, closeout, COMPLETE or CLOSE event exists, checked after the affected tables are locked.
 */
return new class extends Migration
{
    private const GUARD_FN = 'job_lifecycle_evidence_guard_v1()';

    private const CONSISTENCY_FN = 'job_lifecycle_consistency_v1()';

    private const OPS_OLD = "NEW.operation NOT IN ('RELEASE','ASSIGN','HOLD','RESOLVE_HOLD','START')";

    private const OPS_NEW = "NEW.operation NOT IN ('RELEASE','ASSIGN','HOLD','RESOLVE_HOLD','START','COMPLETE','CLOSE')";

    private const TRANSITION_OLD = "   ELSIF NEW.from_state <> NEW.to_state OR NEW.to_state NOT IN ('RELEASED','ACTIVE') THEN\n";

    private const TRANSITION_NEW = <<<'TXT'
   ELSIF NEW.operation = 'COMPLETE' THEN
    IF NEW.from_state <> 'ACTIVE' OR NEW.to_state <> 'COMPLETED' THEN
     RAISE EXCEPTION 'Job % COMPLETE must move ACTIVE to COMPLETED', v_job USING ERRCODE = '23514';
    END IF;
   ELSIF NEW.operation = 'CLOSE' THEN
    IF NEW.from_state <> 'COMPLETED' OR NEW.to_state <> 'CLOSED' THEN
     RAISE EXCEPTION 'Job % CLOSE must move COMPLETED to CLOSED', v_job USING ERRCODE = '23514';
    END IF;
   ELSIF NEW.from_state <> NEW.to_state OR NEW.to_state NOT IN ('RELEASED','ACTIVE') THEN

TXT;

    public function up(): void
    {
        $this->patchFunction(self::GUARD_FN, self::OPS_OLD, self::OPS_NEW);
        $this->patchFunction(self::CONSISTENCY_FN, self::TRANSITION_OLD, self::TRANSITION_NEW);

        Schema::create('job_completions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedBigInteger('baseline_id');
            $t->unsignedBigInteger('release_event_id');
            $t->string('condition_identity', 120);
            $t->string('baseline_fingerprint', 128);
            $t->text('statement');
            $t->unsignedBigInteger('completed_by');
            $t->timestampTz('occurred_at')->nullable();
            $t->string('occurrence_precision', 20);
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_completions_company_id_unique');
            // Exactly one completion per Job, and one per COMPLETE event.
            $t->unique(['company_id', 'job_id'], 'job_completions_job_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'job_completions_event_unique');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'job_completions_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'job_id', 'baseline_id'], 'job_completions_baseline_fk')->references(['company_id', 'job_id', 'id'])->on('job_baselines');
            $t->foreign(['company_id', 'job_id', 'release_event_id'], 'job_completions_origin_fk')->references(['company_id', 'job_id', 'release_event_id'])->on('job_sales_origins');
            $t->foreign(['company_id', 'completed_by'], 'job_completions_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE job_completions ADD CONSTRAINT job_completions_shape CHECK (
            length(btrim(statement)) > 0 AND length(statement) <= 1000 AND length(btrim(condition_identity)) > 0 AND length(btrim(baseline_fingerprint)) > 0
            AND length(btrim(occurrence_precision)) > 0
        )');

        Schema::create('job_closeout_assessments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 120);
            $t->char('intent_hash', 64);
            $t->unsignedInteger('at_generation');
            $t->string('status', 10);
            $t->jsonb('blockers');
            $t->string('source_pin', 128)->nullable();
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_closeout_assessments_company_id_unique');
            $t->unique(['company_id', 'job_id', 'id'], 'job_closeout_assessments_job_unique');
            $t->unique(['company_id', 'actor_id', 'request_key'], 'job_closeout_assessments_key_unique');
            $t->index(['company_id', 'job_id', 'at_generation'], 'job_closeout_assessments_generation_index');
            $t->foreign(['company_id', 'job_id'], 'job_closeout_assessments_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'actor_id'], 'job_closeout_assessments_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE job_closeout_assessments ADD CONSTRAINT job_closeout_assessments_shape CHECK (
            at_generation >= 1 AND status IN ('READY','BLOCKED') AND jsonb_typeof(blockers) = 'array'
            AND ((status = 'READY') = (jsonb_array_length(blockers) = 0)) AND length(btrim(request_key)) > 0 AND intent_hash ~ '^[0-9a-f]{64}\$'
        )");

        Schema::create('job_closeouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedBigInteger('assessment_id');
            $t->text('statement')->nullable();
            $t->unsignedBigInteger('closed_by');
            $t->timestampTz('occurred_at')->nullable();
            $t->string('occurrence_precision', 20);
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'job_closeouts_company_id_unique');
            // Exactly one closeout per Job and per CLOSE event; an assessment is consumed once.
            $t->unique(['company_id', 'job_id'], 'job_closeouts_job_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'job_closeouts_event_unique');
            $t->unique(['company_id', 'assessment_id'], 'job_closeouts_assessment_unique');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'job_closeouts_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'job_id', 'assessment_id'], 'job_closeouts_assessment_fk')->references(['company_id', 'job_id', 'id'])->on('job_closeout_assessments');
            $t->foreign(['company_id', 'closed_by'], 'job_closeouts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE job_closeouts ADD CONSTRAINT job_closeouts_shape CHECK (
            (statement IS NULL OR (length(btrim(statement)) > 0 AND length(statement) <= 1000)) AND length(btrim(occurrence_precision)) > 0
        )');

        DB::unprepared($this->triggers());
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE job_closeouts, job_closeout_assessments, job_completions, job_events, service_jobs IN ACCESS EXCLUSIVE MODE');
        foreach (['job_closeouts', 'job_closeout_assessments', 'job_completions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has rows; rolling this migration back would erase Job completion or closeout evidence. Plan a backout first.");
            }
        }
        if (DB::table('job_events')->whereIn('operation', ['COMPLETE', 'CLOSE'])->exists() || DB::table('service_jobs')->whereIn('state', ['COMPLETED', 'CLOSED'])->exists()) {
            throw new RuntimeException('Jobs have been completed or closed; rolling this migration back would erase Job completion or closeout history. Plan a backout first.');
        }

        foreach (['job_events_completion_consistency' => 'job_events', 'job_completions_completion_consistency' => 'job_completions', 'job_closeouts_completion_consistency' => 'job_closeouts'] as $trigger => $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        Schema::dropIfExists('job_closeouts');
        Schema::dropIfExists('job_closeout_assessments');
        Schema::dropIfExists('job_completions');
        DB::statement('DROP FUNCTION IF EXISTS job_completion_consistency_v1()');
        DB::statement('DROP FUNCTION IF EXISTS job_completion_evidence_guard_v1()');
        $this->patchFunction(self::CONSISTENCY_FN, self::TRANSITION_NEW, self::TRANSITION_OLD);
        $this->patchFunction(self::GUARD_FN, self::OPS_NEW, self::OPS_OLD);
    }

    /**
     * Replace exactly one occurrence of a known fragment in an installed function. Anything else (a function that is missing, or a body that is not the one this
     * migration was written against) stops the migration before a thing is changed: no guess, no overwrite of a different definition.
     */
    private function patchFunction(string $function, string $from, string $to): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('{$function}'::regprocedure) AS def")->def ?? null;
        if (! is_string($definition) || substr_count($definition, $from) !== 1) {
            throw new RuntimeException("The installed {$function} is not the definition this migration extends; nothing was changed.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    private function triggers(): string
    {
        return <<<'SQL'
-- History: the existing Jobs guard refuses UPDATE, DELETE and TRUNCATE on every new table.
CREATE TRIGGER job_completions_guard BEFORE UPDATE OR DELETE ON job_completions FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_closeout_assessments_guard BEFORE UPDATE OR DELETE ON job_closeout_assessments FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_closeouts_guard BEFORE UPDATE OR DELETE ON job_closeouts FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_completions_truncate_guard BEFORE TRUNCATE ON job_completions FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_closeout_assessments_truncate_guard BEFORE TRUNCATE ON job_closeout_assessments FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_closeouts_truncate_guard BEFORE TRUNCATE ON job_closeouts FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();

-- Writers take the Job head lock first (the same stable order as every other Jobs writer) and take the recorded time from the database.
CREATE OR REPLACE FUNCTION job_completion_evidence_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ev job_events%ROWTYPE;
BEGIN
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 IF TG_TABLE_NAME = 'job_closeout_assessments' THEN
  NEW.recorded_at := clock_timestamp();
 ELSE
  SELECT * INTO ev FROM job_events WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.event_id;
  NEW.recorded_at := COALESCE(ev.recorded_at, clock_timestamp());
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER job_completions_evidence_guard BEFORE INSERT ON job_completions FOR EACH ROW EXECUTE FUNCTION job_completion_evidence_guard_v1();
CREATE TRIGGER job_closeout_assessments_evidence_guard BEFORE INSERT ON job_closeout_assessments FOR EACH ROW EXECUTE FUNCTION job_completion_evidence_guard_v1();
CREATE TRIGGER job_closeouts_evidence_guard BEFORE INSERT ON job_closeouts FOR EACH ROW EXECUTE FUNCTION job_completion_evidence_guard_v1();

-- COMMIT-time completeness. A COMPLETE event: exactly its own completion row (actor, time, statement = the event's reason) attesting the ORIGINAL agreed work (the Job's
-- latest baseline, its Sales release and the component's agreed-conditions identity), with no unresolved hold. A CLOSE event: exactly its own closeout row consuming a READY
-- assessment of exactly the generation it closes, after the completion, with no unresolved hold. Every other event carries neither.
CREATE OR REPLACE FUNCTION job_completion_consistency_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 v_company bigint;
 v_job bigint;
 ev job_events%ROWTYPE;
 c job_completions%ROWTYPE;
 k job_closeouts%ROWTYPE;
 a job_closeout_assessments%ROWTYPE;
 jb job_baselines%ROWTYPE;
 so job_sales_origins%ROWTYPE;
 comp job_baseline_components%ROWTYPE;
 n_c int;
 n_k int;
BEGIN
 v_company := NEW.company_id; v_job := NEW.job_id;
 IF TG_TABLE_NAME = 'job_events' THEN
  ev := NEW;
 ELSE
  SELECT * INTO ev FROM job_events WHERE company_id = v_company AND job_id = v_job AND id = NEW.event_id;
  IF NOT FOUND THEN
   RAISE EXCEPTION 'Job % completion or closeout evidence names no event', v_job USING ERRCODE = '23514';
  END IF;
 END IF;

 SELECT count(*) INTO n_c FROM job_completions WHERE company_id = v_company AND job_id = v_job AND event_id = ev.id;
 SELECT count(*) INTO n_k FROM job_closeouts WHERE company_id = v_company AND job_id = v_job AND event_id = ev.id;
 IF (n_c, n_k) IS DISTINCT FROM (CASE WHEN ev.operation = 'COMPLETE' THEN 1 ELSE 0 END, CASE WHEN ev.operation = 'CLOSE' THEN 1 ELSE 0 END) THEN
  RAISE EXCEPTION 'Job % event % (%) does not have exactly its own completion or closeout evidence', v_job, ev.sequence, ev.operation USING ERRCODE = '23514';
 END IF;

 IF ev.operation IN ('COMPLETE', 'CLOSE') THEN
  IF EXISTS (SELECT 1 FROM job_holds hd JOIN job_events he ON he.company_id = hd.company_id AND he.job_id = hd.job_id AND he.id = hd.event_id
    WHERE hd.company_id = v_company AND hd.job_id = v_job AND he.sequence < ev.sequence
    AND NOT EXISTS (SELECT 1 FROM job_hold_resolutions r JOIN job_events re ON re.company_id = r.company_id AND re.job_id = r.job_id AND re.id = r.event_id
      WHERE r.company_id = hd.company_id AND r.hold_id = hd.id AND re.sequence < ev.sequence)) THEN
   RAISE EXCEPTION 'Job % has an unresolved hold and cannot %', v_job, lower(ev.operation) USING ERRCODE = '23514';
  END IF;
 END IF;

 IF ev.operation = 'COMPLETE' THEN
  SELECT * INTO c FROM job_completions WHERE company_id = v_company AND job_id = v_job AND event_id = ev.id;
  SELECT * INTO jb FROM job_baselines WHERE company_id = v_company AND job_id = v_job ORDER BY version DESC LIMIT 1;
  SELECT * INTO so FROM job_sales_origins WHERE company_id = v_company AND job_id = v_job;
  SELECT * INTO comp FROM job_baseline_components WHERE company_id = v_company AND job_id = v_job AND baseline_id = jb.id;
  IF c.completed_by <> ev.actor_id OR c.occurred_at IS DISTINCT FROM ev.occurred_at OR c.occurrence_precision <> ev.occurrence_precision OR c.statement IS DISTINCT FROM ev.reason THEN
   RAISE EXCEPTION 'Job % completion does not agree with its COMPLETE event (actor, claimed time, precision, statement)', v_job USING ERRCODE = '23514';
  END IF;
  IF jb.id IS NULL OR so.id IS NULL OR comp.id IS NULL OR c.baseline_id <> jb.id OR c.baseline_fingerprint <> jb.fingerprint
   OR c.release_event_id <> so.release_event_id OR c.condition_identity <> comp.condition_identity THEN
   RAISE EXCEPTION 'Job % completion must attest the original agreed work it was released with (baseline, release, conditions)', v_job USING ERRCODE = '23514';
  END IF;
 ELSIF ev.operation = 'CLOSE' THEN
  SELECT * INTO k FROM job_closeouts WHERE company_id = v_company AND job_id = v_job AND event_id = ev.id;
  SELECT * INTO a FROM job_closeout_assessments WHERE company_id = v_company AND job_id = v_job AND id = k.assessment_id;
  IF k.closed_by <> ev.actor_id OR k.occurred_at IS DISTINCT FROM ev.occurred_at OR k.occurrence_precision <> ev.occurrence_precision OR k.statement IS DISTINCT FROM ev.reason THEN
   RAISE EXCEPTION 'Job % closeout does not agree with its CLOSE event (actor, claimed time, precision, statement)', v_job USING ERRCODE = '23514';
  END IF;
  IF a.id IS NULL OR a.status <> 'READY' OR a.at_generation <> ev.prior_generation THEN
   RAISE EXCEPTION 'Job % can only be closed on a READY assessment of the generation being closed', v_job USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM job_completions jc JOIN job_events ce ON ce.company_id = jc.company_id AND ce.job_id = jc.job_id AND ce.id = jc.event_id
    WHERE jc.company_id = v_company AND jc.job_id = v_job AND ce.sequence < ev.sequence) THEN
   RAISE EXCEPTION 'Job % cannot be closed before it was completed', v_job USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER job_events_completion_consistency AFTER INSERT ON job_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_completion_consistency_v1();
CREATE CONSTRAINT TRIGGER job_completions_completion_consistency AFTER INSERT ON job_completions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_completion_consistency_v1();
CREATE CONSTRAINT TRIGGER job_closeouts_completion_consistency AFTER INSERT ON job_closeouts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_completion_consistency_v1();
SQL;
    }
};
