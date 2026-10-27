<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5, J1-F1 (docs/phases/phase-5-task-d-j1-foundation.md and phase-5-task-d-j1-f1-proposal.md): the disabled Job lifecycle database foundation — four more
 * company-scoped evidence tables, two identity columns on job_events, and the database-side invariants that make a lifecycle chain coherent. Nothing here can
 * create or change a real Job: no Action, receiver, route, permission or grant accompanies it, and the schema is disabled by the absence of an entry point.
 *
 *  - JOB_ASSIGNMENT_EVENTS record who is responsible for the Job, as a continuous same-Job chain (no fork, no gap). The responsible person is a company user, never a label.
 *  - JOB_HOLDS and JOB_HOLD_RESOLUTIONS record independent holds and the named resolution of each. A hold row is never "cleared"; resolving one cannot clear another.
 *  - JOB_OPERATION_RECEIPTS record that an actor's request key produced a result event, so a replay resolves to the original event instead of repeating it.
 *  - JOB_EVENTS gain a command identity and a semantic intent hash (present together or absent together) and a (company, job, id) key for same-Job foreign keys.
 *  - Every new table is history: the database refuses UPDATE, DELETE and TRUNCATE (CASCADE included) through the existing job_evidence_append_only_guard().
 *  - Chain coherence is enforced at COMMIT by deferrable constraint triggers (job_lifecycle_consistency_v1), so rows may be inserted in any order inside one transaction
 *    but a partial chain can never commit. The writers' head lock is taken first, in BEFORE INSERT triggers (job_lifecycle_evidence_guard_v1).
 *
 * Installation tightens a disabled SP0b, so it first checks that anything already stored is coherent and aborts, touching nothing, if it is not. Rolling back refuses
 * while any new row or F1 identity exists, checked after the affected tables are locked.
 */
return new class extends Migration
{
    private const OPERATIONS = "('RELEASE','ASSIGN','HOLD','RESOLVE_HOLD','START')";

    public function up(): void
    {
        $this->preflight();

        DB::statement('ALTER TABLE job_events ADD COLUMN command_identity uuid NULL');
        DB::statement('ALTER TABLE job_events ADD COLUMN semantic_intent_hash char(64) NULL');
        DB::statement("ALTER TABLE job_events ADD CONSTRAINT job_events_identity_shape CHECK (
            (command_identity IS NULL) = (semantic_intent_hash IS NULL) AND (semantic_intent_hash IS NULL OR semantic_intent_hash ~ '^[0-9a-f]{64}$')
        )");
        DB::statement('ALTER TABLE job_events ADD CONSTRAINT job_events_job_unique UNIQUE (company_id, job_id, id)');
        DB::statement('ALTER TABLE job_events ADD CONSTRAINT job_events_command_unique UNIQUE (company_id, job_id, command_identity)');

        Schema::create('job_assignment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedInteger('sequence');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->unsignedBigInteger('responsible_user_id');
            $t->timestampTz('intended_from')->nullable();
            $t->timestampTz('intended_until')->nullable();
            $t->text('instructions')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('occurred_at')->nullable();
            $t->string('occurrence_precision', 20);
            $t->text('reason')->nullable();
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_assignment_events_company_id_unique');
            $t->unique(['company_id', 'job_id', 'id'], 'job_assignment_events_job_unique');
            $t->unique(['company_id', 'job_id', 'sequence'], 'job_assignment_events_sequence_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'job_assignment_events_event_unique');
            $t->foreign(['company_id', 'job_id'], 'job_assignment_events_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'job_assignment_events_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'job_id', 'predecessor_id'], 'job_assignment_events_predecessor_fk')->references(['company_id', 'job_id', 'id'])->on('job_assignment_events');
            $t->foreign(['company_id', 'responsible_user_id'], 'job_assignment_events_responsible_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'actor_id'], 'job_assignment_events_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE job_assignment_events ADD CONSTRAINT job_assignment_events_shape CHECK (
            sequence >= 1 AND ((sequence = 1) = (predecessor_id IS NULL))
            AND (intended_until IS NULL OR (intended_from IS NOT NULL AND intended_until >= intended_from))
            AND (instructions IS NULL OR length(trim(instructions)) > 0) AND (reason IS NULL OR length(trim(reason)) > 0)
            AND length(trim(occurrence_precision)) > 0
        )');

        Schema::create('job_holds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->string('scope_kind', 12);
            $t->text('reason');
            $t->timestampTz('effective_from');
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_holds_company_id_unique');
            $t->unique(['company_id', 'job_id', 'id'], 'job_holds_job_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'job_holds_event_unique');
            $t->foreign(['company_id', 'job_id'], 'job_holds_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'job_holds_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'actor_id'], 'job_holds_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE job_holds ADD CONSTRAINT job_holds_shape CHECK (scope_kind = 'JOB' AND length(trim(reason)) > 0)");

        Schema::create('job_hold_resolutions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedBigInteger('hold_id');
            $t->timestampTz('resolved_effective_at');
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_hold_resolutions_company_id_unique');
            $t->unique(['company_id', 'hold_id'], 'job_hold_resolutions_hold_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'job_hold_resolutions_event_unique');
            $t->foreign(['company_id', 'job_id'], 'job_hold_resolutions_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'job_hold_resolutions_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'job_id', 'hold_id'], 'job_hold_resolutions_hold_fk')->references(['company_id', 'job_id', 'id'])->on('job_holds');
            $t->foreign(['company_id', 'actor_id'], 'job_hold_resolutions_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE job_hold_resolutions ADD CONSTRAINT job_hold_resolutions_shape CHECK (length(trim(reason)) > 0)');

        Schema::create('job_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 120);
            $t->string('operation', 60);
            $t->char('intent_hash', 64);
            $t->unsignedBigInteger('result_event_id');
            $t->unsignedInteger('contract_version');
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'job_operation_receipts_company_id_unique');
            $t->unique(['company_id', 'actor_id', 'request_key'], 'job_operation_receipts_key_unique');
            $t->index(['company_id', 'job_id', 'result_event_id'], 'job_operation_receipts_result_index');
            $t->foreign(['company_id', 'job_id'], 'job_operation_receipts_job_fk')->references(['company_id', 'id'])->on('service_jobs');
            $t->foreign(['company_id', 'job_id', 'result_event_id'], 'job_operation_receipts_event_fk')->references(['company_id', 'job_id', 'id'])->on('job_events');
            $t->foreign(['company_id', 'actor_id'], 'job_operation_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE job_operation_receipts ADD CONSTRAINT job_operation_receipts_shape CHECK (
            length(trim(request_key)) > 0 AND length(trim(operation)) > 0 AND intent_hash ~ '^[0-9a-f]{64}$' AND contract_version >= 1
        )");

        DB::unprepared($this->functionsAndTriggers());
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE job_operation_receipts, job_hold_resolutions, job_holds, job_assignment_events, job_events, service_jobs IN ACCESS EXCLUSIVE MODE');
        foreach (['job_operation_receipts', 'job_hold_resolutions', 'job_holds', 'job_assignment_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has rows; rolling this migration back would erase Job lifecycle evidence. Plan a backout first.");
            }
        }
        if (DB::table('job_events')->whereNotNull('command_identity')->exists()) {
            throw new RuntimeException('job_events carry a lifecycle command identity; rolling this migration back would erase Job lifecycle evidence. Plan a backout first.');
        }

        foreach ([
            'job_events_lifecycle_guard' => 'job_events', 'job_events_lifecycle_consistency' => 'job_events',
            'service_jobs_lifecycle_head_guard' => 'service_jobs', 'service_jobs_lifecycle_consistency' => 'service_jobs',
        ] as $trigger => $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        Schema::dropIfExists('job_operation_receipts');
        Schema::dropIfExists('job_hold_resolutions');
        Schema::dropIfExists('job_holds');
        Schema::dropIfExists('job_assignment_events');
        DB::statement('DROP FUNCTION IF EXISTS job_lifecycle_consistency_v1()');
        DB::statement('DROP FUNCTION IF EXISTS job_lifecycle_evidence_guard_v1()');
        DB::statement('DROP FUNCTION IF EXISTS job_lifecycle_head_guard_v1()');
        DB::statement('ALTER TABLE job_events DROP CONSTRAINT IF EXISTS job_events_command_unique');
        DB::statement('ALTER TABLE job_events DROP CONSTRAINT IF EXISTS job_events_job_unique');
        DB::statement('ALTER TABLE job_events DROP CONSTRAINT IF EXISTS job_events_identity_shape');
        DB::statement('ALTER TABLE job_events DROP COLUMN IF EXISTS semantic_intent_hash');
        DB::statement('ALTER TABLE job_events DROP COLUMN IF EXISTS command_identity');
        // job_evidence_append_only_guard() and every SP0b object belong to the SP0b migration and are left exactly as they are.
    }

    /**
     * Anything SP0b already holds must satisfy the rules this migration adds, or the install stops before changing a thing. No release or start event is
     * fabricated, no history rewritten and no row deleted to make it fit: an incompatible store needs its own reviewed compatibility plan.
     */
    private function preflight(): void
    {
        $problems = [
            'jobs with no lifecycle event' => 'SELECT count(*) AS n FROM service_jobs j WHERE NOT EXISTS (SELECT 1 FROM job_events e WHERE e.company_id = j.company_id AND e.job_id = j.id)',
            'events outside the release-only history SP0b can hold' => "SELECT count(*) AS n FROM job_events e WHERE e.operation <> 'RELEASE'",
            'first events that are not a coherent release' => "SELECT count(*) AS n FROM job_events e WHERE e.sequence = 1 AND NOT (e.operation = 'RELEASE' AND e.prior_generation = 0 AND e.next_generation = 1 AND e.from_state IS NULL AND e.to_state = 'RELEASED')",
            'jobs whose history does not start at sequence 1 or has a gap' => 'SELECT count(*) AS n FROM (SELECT company_id, job_id FROM job_events GROUP BY company_id, job_id HAVING min(sequence) <> 1 OR max(sequence) <> count(*)) g',
            'events that do not continue their predecessor' => 'SELECT count(*) AS n FROM job_events e JOIN job_events p ON p.company_id = e.company_id AND p.job_id = e.job_id AND p.sequence = e.sequence - 1
                WHERE e.prior_generation <> p.next_generation OR e.next_generation <> e.prior_generation + 1 OR e.from_state IS DISTINCT FROM p.to_state',
            'jobs whose head differs from the latest event' => 'SELECT count(*) AS n FROM service_jobs j JOIN job_events e ON e.company_id = j.company_id AND e.job_id = j.id
                WHERE e.sequence = (SELECT max(m.sequence) FROM job_events m WHERE m.company_id = j.company_id AND m.job_id = j.id) AND (j.state <> e.to_state OR j.generation <> e.next_generation)',
        ];
        $found = [];
        foreach ($problems as $label => $sql) {
            $count = (int) DB::selectOne($sql)->n;
            if ($count > 0) {
                $found[] = "{$count} {$label}";
            }
        }
        if ($found !== []) {
            throw new RuntimeException('Existing Job evidence is not compatible with the J1-F1 lifecycle foundation ('.implode('; ', $found).'). Nothing was changed; a reviewed compatibility plan is required.');
        }
    }

    private function functionsAndTriggers(): string
    {
        $ops = self::OPERATIONS;

        return <<<SQL
-- History: the existing Jobs guard (never the Documents one) refuses UPDATE, DELETE and TRUNCATE on every new table.
CREATE TRIGGER job_assignment_events_guard BEFORE UPDATE OR DELETE ON job_assignment_events FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_holds_guard BEFORE UPDATE OR DELETE ON job_holds FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_hold_resolutions_guard BEFORE UPDATE OR DELETE ON job_hold_resolutions FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_operation_receipts_guard BEFORE UPDATE OR DELETE ON job_operation_receipts FOR EACH ROW EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_assignment_events_truncate_guard BEFORE TRUNCATE ON job_assignment_events FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_holds_truncate_guard BEFORE TRUNCATE ON job_holds FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_hold_resolutions_truncate_guard BEFORE TRUNCATE ON job_hold_resolutions FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();
CREATE TRIGGER job_operation_receipts_truncate_guard BEFORE TRUNCATE ON job_operation_receipts FOR EACH STATEMENT EXECUTE FUNCTION job_evidence_append_only_guard();

-- Writers: take the Job head lock first (stable order), then refuse what is wrong on its face. Runs before any foreign-key check, so a competing writer waits here.
CREATE OR REPLACE FUNCTION job_lifecycle_evidence_guard_v1() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE
 pa job_assignment_events%ROWTYPE;
 ev job_events%ROWTYPE;
BEGIN
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 IF TG_TABLE_NAME = 'job_events' THEN
  IF NEW.operation NOT IN {$ops} THEN
   RAISE EXCEPTION 'Job event operation % is not allowed by the lifecycle foundation', NEW.operation USING ERRCODE = '23514';
  END IF;
  IF NEW.operation <> 'RELEASE' AND NEW.command_identity IS NULL THEN
   RAISE EXCEPTION 'Job event operation % needs a command identity and a semantic intent hash', NEW.operation USING ERRCODE = '23514';
  END IF;
 ELSIF TG_TABLE_NAME = 'job_assignment_events' THEN
  IF NEW.predecessor_id IS NOT NULL THEN
   SELECT * INTO pa FROM job_assignment_events WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.predecessor_id;
   IF NOT FOUND OR pa.sequence <> NEW.sequence - 1 THEN
    RAISE EXCEPTION 'Job assignment % must continue the assignment with sequence %', NEW.sequence, NEW.sequence - 1 USING ERRCODE = '23514';
   END IF;
  END IF;
 ELSIF TG_TABLE_NAME = 'job_operation_receipts' THEN
  SELECT * INTO ev FROM job_events WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.result_event_id;
  IF NOT FOUND OR ev.operation = 'RELEASE' OR ev.operation <> NEW.operation THEN
   RAISE EXCEPTION 'Job receipt operation % must name the lifecycle operation of its result event', NEW.operation USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END \$\$;
CREATE TRIGGER job_events_lifecycle_guard BEFORE INSERT ON job_events FOR EACH ROW EXECUTE FUNCTION job_lifecycle_evidence_guard_v1();
CREATE TRIGGER job_assignment_events_lifecycle_guard BEFORE INSERT ON job_assignment_events FOR EACH ROW EXECUTE FUNCTION job_lifecycle_evidence_guard_v1();
CREATE TRIGGER job_holds_lifecycle_guard BEFORE INSERT ON job_holds FOR EACH ROW EXECUTE FUNCTION job_lifecycle_evidence_guard_v1();
CREATE TRIGGER job_hold_resolutions_lifecycle_guard BEFORE INSERT ON job_hold_resolutions FOR EACH ROW EXECUTE FUNCTION job_lifecycle_evidence_guard_v1();
CREATE TRIGGER job_operation_receipts_lifecycle_guard BEFORE INSERT ON job_operation_receipts FOR EACH ROW EXECUTE FUNCTION job_lifecycle_evidence_guard_v1();

-- The head's identity is fixed once it has evidence.
CREATE OR REPLACE FUNCTION job_lifecycle_head_guard_v1() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
 IF (NEW.company_id, NEW.id, NEW.code) IS DISTINCT FROM (OLD.company_id, OLD.id, OLD.code)
  AND EXISTS (SELECT 1 FROM job_events WHERE company_id = OLD.company_id AND job_id = OLD.id) THEN
  RAISE EXCEPTION 'A Job with lifecycle evidence cannot change its company, id or code' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END \$\$;
CREATE TRIGGER service_jobs_lifecycle_head_guard BEFORE UPDATE ON service_jobs FOR EACH ROW EXECUTE FUNCTION job_lifecycle_head_guard_v1();

-- COMMIT-time coherence: each appended edge against its predecessor, each child against its event, and the head against the latest event. Deferred, so rows may
-- be inserted in any order inside the transaction; a partial chain cannot commit. Only the appended edge and the head are read, never the whole history.
CREATE OR REPLACE FUNCTION job_lifecycle_consistency_v1() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE
 v_company bigint;
 v_job bigint;
 head service_jobs%ROWTYPE;
 latest job_events%ROWTYPE;
 ev job_events%ROWTYPE;
 pred job_events%ROWTYPE;
 other_ev job_events%ROWTYPE;
 h job_holds%ROWTYPE;
 pa job_assignment_events%ROWTYPE;
 n_assign int;
 n_hold int;
 n_resolve int;
BEGIN
 IF TG_TABLE_NAME = 'service_jobs' THEN
  v_company := NEW.company_id; v_job := NEW.id;
 ELSE
  v_company := NEW.company_id; v_job := NEW.job_id;
 END IF;

 SELECT * INTO head FROM service_jobs WHERE company_id = v_company AND id = v_job;
 IF NOT FOUND THEN
  RETURN NULL;
 END IF;
 SELECT * INTO latest FROM job_events WHERE company_id = v_company AND job_id = v_job ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND THEN
  RAISE EXCEPTION 'Job % has no lifecycle event', v_job USING ERRCODE = '23514';
 END IF;
 IF head.state <> latest.to_state OR head.generation <> latest.next_generation THEN
  RAISE EXCEPTION 'Job % head (% / generation %) differs from its latest event (% / generation %)', v_job, head.state, head.generation, latest.to_state, latest.next_generation USING ERRCODE = '23514';
 END IF;

 IF TG_TABLE_NAME = 'job_events' THEN
  IF NEW.sequence = 1 THEN
   IF NEW.operation <> 'RELEASE' OR NEW.prior_generation <> 0 OR NEW.next_generation <> 1 OR NEW.from_state IS NOT NULL OR NEW.to_state <> 'RELEASED' THEN
    RAISE EXCEPTION 'Job % first event must be the initial release (sequence 1, generation 0 to 1, RELEASED)', v_job USING ERRCODE = '23514';
   END IF;
  ELSE
   SELECT * INTO pred FROM job_events WHERE company_id = v_company AND job_id = v_job AND sequence = NEW.sequence - 1;
   IF NOT FOUND THEN
    RAISE EXCEPTION 'Job % event sequence % has no predecessor', v_job, NEW.sequence USING ERRCODE = '23514';
   END IF;
   IF NEW.operation = 'RELEASE' THEN
    RAISE EXCEPTION 'Job % can only be released at sequence 1', v_job USING ERRCODE = '23514';
   END IF;
   IF NEW.prior_generation <> pred.next_generation OR NEW.next_generation <> NEW.prior_generation + 1 OR NEW.from_state IS DISTINCT FROM pred.to_state THEN
    RAISE EXCEPTION 'Job % event % does not continue event %', v_job, NEW.sequence, pred.sequence USING ERRCODE = '23514';
   END IF;
   IF NEW.operation = 'START' THEN
    IF NEW.from_state <> 'RELEASED' OR NEW.to_state <> 'ACTIVE' THEN
     RAISE EXCEPTION 'Job % START must move RELEASED to ACTIVE', v_job USING ERRCODE = '23514';
    END IF;
   ELSIF NEW.from_state <> NEW.to_state OR NEW.to_state NOT IN ('RELEASED','ACTIVE') THEN
    RAISE EXCEPTION 'Job % operation % retains the RELEASED or ACTIVE state', v_job, NEW.operation USING ERRCODE = '23514';
   END IF;
  END IF;

  SELECT count(*) INTO n_assign FROM job_assignment_events WHERE company_id = v_company AND job_id = v_job AND event_id = NEW.id;
  SELECT count(*) INTO n_hold FROM job_holds WHERE company_id = v_company AND job_id = v_job AND event_id = NEW.id;
  SELECT count(*) INTO n_resolve FROM job_hold_resolutions WHERE company_id = v_company AND job_id = v_job AND event_id = NEW.id;
  IF (n_assign, n_hold, n_resolve) IS DISTINCT FROM (
   CASE WHEN NEW.operation = 'ASSIGN' THEN 1 ELSE 0 END, CASE WHEN NEW.operation = 'HOLD' THEN 1 ELSE 0 END, CASE WHEN NEW.operation = 'RESOLVE_HOLD' THEN 1 ELSE 0 END) THEN
   RAISE EXCEPTION 'Job % event % (%) does not have exactly its own child evidence', v_job, NEW.sequence, NEW.operation USING ERRCODE = '23514';
  END IF;

  IF NEW.operation = 'START' THEN
   IF NOT EXISTS (SELECT 1 FROM job_assignment_events a JOIN job_events ae ON ae.company_id = a.company_id AND ae.job_id = a.job_id AND ae.id = a.event_id
     WHERE a.company_id = v_company AND a.job_id = v_job AND ae.sequence < NEW.sequence) THEN
    RAISE EXCEPTION 'Job % cannot start without a recorded responsibility', v_job USING ERRCODE = '23514';
   END IF;
   IF EXISTS (SELECT 1 FROM job_holds hd JOIN job_events he ON he.company_id = hd.company_id AND he.job_id = hd.job_id AND he.id = hd.event_id
     WHERE hd.company_id = v_company AND hd.job_id = v_job AND he.sequence < NEW.sequence
     AND NOT EXISTS (SELECT 1 FROM job_hold_resolutions r JOIN job_events re ON re.company_id = r.company_id AND re.job_id = r.job_id AND re.id = r.event_id
       WHERE r.company_id = hd.company_id AND r.hold_id = hd.id AND re.sequence < NEW.sequence)) THEN
    RAISE EXCEPTION 'Job % has an unresolved hold and cannot start', v_job USING ERRCODE = '23514';
   END IF;
  END IF;

  IF NEW.operation <> 'RELEASE' AND NOT EXISTS (SELECT 1 FROM job_operation_receipts WHERE company_id = v_company AND job_id = v_job AND result_event_id = NEW.id AND actor_id = NEW.actor_id) THEN
   RAISE EXCEPTION 'Job % event % has no receipt from its own actor', v_job, NEW.sequence USING ERRCODE = '23514';
  END IF;

 ELSIF TG_TABLE_NAME IN ('job_assignment_events', 'job_holds', 'job_hold_resolutions') THEN
  SELECT * INTO ev FROM job_events WHERE company_id = v_company AND job_id = v_job AND id = NEW.event_id;
  IF TG_TABLE_NAME = 'job_assignment_events' THEN
   IF ev.operation <> 'ASSIGN' OR ev.actor_id <> NEW.actor_id OR ev.occurred_at IS DISTINCT FROM NEW.occurred_at
    OR ev.occurrence_precision <> NEW.occurrence_precision OR ev.reason IS DISTINCT FROM NEW.reason THEN
    RAISE EXCEPTION 'Job assignment % does not agree with its ASSIGN event', NEW.sequence USING ERRCODE = '23514';
   END IF;
   IF NEW.predecessor_id IS NOT NULL THEN
    SELECT * INTO pa FROM job_assignment_events WHERE company_id = v_company AND job_id = v_job AND id = NEW.predecessor_id;
    SELECT * INTO other_ev FROM job_events WHERE company_id = v_company AND job_id = v_job AND id = pa.event_id;
    IF other_ev.sequence >= ev.sequence THEN
     RAISE EXCEPTION 'Job assignment % must come after the assignment it succeeds', NEW.sequence USING ERRCODE = '23514';
    END IF;
   END IF;
  ELSIF TG_TABLE_NAME = 'job_holds' THEN
   IF ev.operation <> 'HOLD' OR ev.actor_id <> NEW.actor_id OR ev.reason IS DISTINCT FROM NEW.reason OR NEW.effective_from <> ev.recorded_at THEN
    RAISE EXCEPTION 'Job hold does not agree with its HOLD event (actor, reason, and effective at the recorded server time)' USING ERRCODE = '23514';
   END IF;
  ELSE
   SELECT * INTO h FROM job_holds WHERE company_id = v_company AND job_id = v_job AND id = NEW.hold_id;
   SELECT * INTO other_ev FROM job_events WHERE company_id = v_company AND job_id = v_job AND id = h.event_id;
   IF ev.operation <> 'RESOLVE_HOLD' OR ev.actor_id <> NEW.actor_id OR ev.reason IS DISTINCT FROM NEW.reason OR NEW.resolved_effective_at <> ev.recorded_at THEN
    RAISE EXCEPTION 'Job hold resolution does not agree with its RESOLVE_HOLD event (actor, reason, and effective at the recorded server time)' USING ERRCODE = '23514';
   END IF;
   IF other_ev.sequence >= ev.sequence OR NEW.resolved_effective_at < h.effective_from THEN
    RAISE EXCEPTION 'Job hold resolution must come after, and not before, the hold it resolves' USING ERRCODE = '23514';
   END IF;
  END IF;
 END IF;
 RETURN NULL;
END \$\$;
CREATE CONSTRAINT TRIGGER job_events_lifecycle_consistency AFTER INSERT ON job_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_lifecycle_consistency_v1();
CREATE CONSTRAINT TRIGGER service_jobs_lifecycle_consistency AFTER INSERT OR UPDATE ON service_jobs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_lifecycle_consistency_v1();
CREATE CONSTRAINT TRIGGER job_assignment_events_lifecycle_consistency AFTER INSERT ON job_assignment_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_lifecycle_consistency_v1();
CREATE CONSTRAINT TRIGGER job_holds_lifecycle_consistency AFTER INSERT ON job_holds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_lifecycle_consistency_v1();
CREATE CONSTRAINT TRIGGER job_hold_resolutions_lifecycle_consistency AFTER INSERT ON job_hold_resolutions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION job_lifecycle_consistency_v1();
SQL;
    }
};
