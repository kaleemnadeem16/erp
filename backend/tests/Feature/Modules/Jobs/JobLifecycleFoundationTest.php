<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Jobs\Models\JobAssignmentEvent;
use App\Modules\Jobs\Models\JobHold;
use App\Modules\Jobs\Models\JobHoldResolution;
use App\Modules\Jobs\Models\JobOperationReceipt;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\TestCase;

/**
 * J1-F1 — the disabled Job lifecycle database foundation over the real database, by raw SQL: company and same-Job keys, append-only AND truncate-guarded evidence,
 * and the commit-time chain checks. A test transaction never commits, so "commit" here is SET CONSTRAINTS ALL IMMEDIATE, which runs exactly the deferred checks COMMIT
 * would; JobLifecycleCommitTest proves the real COMMIT and two-session behaviour. Rows are fixtures: nothing in the application creates a Job.
 */
class JobLifecycleFoundationTest extends TestCase
{
    use BuildsLifecycleChains;
    use RefreshDatabase;

    private User $user;

    private User $worker;

    private int $otherCompany;

    private int $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /** The write must be refused when the transaction would commit (or earlier, by a key or CHECK). */
    private function assertRefused(Closure $write, string $contains): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertTrue(collect(explode('|', $contains))->contains(fn (string $needle) => str_contains($e->getMessage(), $needle)), "Expected one of [{$contains}] in: ".$e->getMessage());

            return;
        } finally {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    private function assertCommits(Closure $write): void
    {
        DB::transaction(function () use ($write): void {
            $write();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->addToAssertionCount(1);
    }

    public function test_the_lifecycle_objects_exist_and_the_event_identity_is_additive(): void
    {
        foreach (['job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_operation_receipts'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$table} is company-scoped");
            $this->assertTrue(Schema::hasColumn($table, 'recorded_at'), $table);
        }
        $this->assertTrue(Schema::hasColumns('job_events', ['command_identity', 'semantic_intent_hash']));
        foreach (['activity_id', 'team_id', 'employee_id', 'amount', 'currency_id', 'hours', 'cost', 'completed_at', 'closed_at'] as $column) {
            foreach (['job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_operation_receipts'] as $table) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table} must not carry {$column}");
            }
        }
        $this->assertSame(['job_events_lifecycle_consistency', 'job_events_lifecycle_guard'], collect(DB::select(
            "select t.tgname from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and c.relname = 'job_events' and p.proname like 'job_lifecycle_%' order by 1"
        ))->pluck('tgname')->all());
    }

    public function test_the_models_are_scoped_storage_shapes_only(): void
    {
        $job = $this->newJob();
        $assigned = $this->assign($job, $this->worker->id);
        $this->settle();
        $this->assertSame(1, JobAssignmentEvent::query()->where('event_id', $assigned)->count());
        $this->assertSame($this->worker->id, JobAssignmentEvent::query()->sole()->responsible_user_id);

        // Scoped to the current company: another company's rows are invisible through the models.
        $theirs = $this->newJob('JOB-1', $this->otherCompany);
        $this->assign($theirs, $this->otherUser);
        $this->settle();
        $this->assertSame(1, JobAssignmentEvent::query()->count());
        foreach ([JobAssignmentEvent::class, JobHold::class, JobHoldResolution::class, JobOperationReceipt::class] as $model) {
            $methods = array_map(fn ($m) => $m->name, (new \ReflectionClass($model))->getMethods(\ReflectionMethod::IS_PUBLIC));
            $own = array_filter($methods, fn ($name) => (new \ReflectionMethod($model, $name))->class === $model);
            $this->assertSame([], array_values($own), "{$model} declares no behaviour");
        }
    }

    public function test_the_database_refuses_cross_company_and_wrong_job_references(): void
    {
        $mine = $this->newJob('A');
        $sibling = $this->newJob('B');
        $theirs = $this->newJob('A', $this->otherCompany);
        $this->settle();
        $event = $this->latest($mine);
        $siblingEvent = $this->latest($sibling);
        $theirEvent = $this->latest($theirs);
        $base = ['occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(5)];

        // Assignment: another company's job, event, responsible user or actor; and a same-company event that belongs to another Job.
        $assignment = ['company_id' => 1, 'job_id' => $mine, 'event_id' => $event->id, 'sequence' => 1, 'responsible_user_id' => $this->worker->id, 'actor_id' => $this->user->id, ...$base];
        $this->assertRefused(fn () => DB::table('job_assignment_events')->insert([...$assignment, 'company_id' => $this->otherCompany]), 'job_assignment_events_job_fk');
        $this->assertRefused(fn () => DB::table('job_assignment_events')->insert([...$assignment, 'event_id' => $theirEvent->id]), 'job_assignment_events_event_fk');
        $this->assertRefused(fn () => DB::table('job_assignment_events')->insert([...$assignment, 'event_id' => $siblingEvent->id]), 'job_assignment_events_event_fk');
        $this->assertRefused(fn () => DB::table('job_assignment_events')->insert([...$assignment, 'responsible_user_id' => $this->otherUser]), 'job_assignment_events_responsible_fk');
        $this->assertRefused(fn () => DB::table('job_assignment_events')->insert([...$assignment, 'actor_id' => $this->otherUser]), 'job_assignment_events_actor_fk');

        // Hold and resolution: the event must be the same Job's; the resolved hold must be the same Job's.
        $hold = ['company_id' => 1, 'job_id' => $mine, 'event_id' => $siblingEvent->id, 'scope_kind' => 'JOB', 'reason' => 'x', 'effective_from' => $this->at(5), 'actor_id' => $this->user->id, 'recorded_at' => $this->at(5)];
        $this->assertRefused(fn () => DB::table('job_holds')->insert($hold), 'job_holds_event_fk');
        $this->assertRefused(fn () => DB::table('job_holds')->insert([...$hold, 'event_id' => $event->id, 'actor_id' => $this->otherUser]), 'job_holds_actor_fk');
        $placed = $this->placeHold($sibling);
        $this->settle();
        $resolution = ['company_id' => 1, 'job_id' => $mine, 'event_id' => $event->id, 'hold_id' => $placed['hold'], 'resolved_effective_at' => $this->at(5), 'actor_id' => $this->user->id, 'reason' => 'x', 'recorded_at' => $this->at(5)];
        $this->assertRefused(fn () => DB::table('job_hold_resolutions')->insert($resolution), 'job_hold_resolutions_hold_fk');

        // Receipt: the result event must be the same Job's and the actor the same company's.
        $receipt = ['company_id' => 1, 'job_id' => $mine, 'actor_id' => $this->user->id, 'request_key' => 'k', 'operation' => 'ASSIGN', 'intent_hash' => str_repeat('a', 64), 'result_event_id' => $siblingEvent->id, 'contract_version' => 1, 'recorded_at' => $this->at(5)];
        $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert($receipt), 'must name the lifecycle operation of its result event|job_operation_receipts_event_fk');
        $assigned = $this->assign($mine, $this->worker->id);
        $this->settle();
        $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'result_event_id' => $assigned, 'actor_id' => $this->otherUser]), 'job_operation_receipts_actor_fk');

        // The additive (company, job, id) key is what makes "same Job" checkable at all.
        $this->assertTrue((bool) DB::selectOne("select count(*) > 0 as ok from pg_constraint where conname = 'job_events_job_unique'")->ok);
    }

    public function test_every_new_table_is_append_only_and_truncate_guarded(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $placed = $this->placeHold($job);
        $this->resolveHold($job, $placed['hold']);
        $this->settle();

        $changes = ['job_assignment_events' => ['instructions' => 'x'], 'job_holds' => ['reason' => 'x'], 'job_hold_resolutions' => ['reason' => 'x'], 'job_operation_receipts' => ['request_key' => 'x']];
        foreach ($changes as $table => $change) {
            $this->assertRefused(fn () => DB::table($table)->update($change), 'append-only');
            $this->assertRefused(fn () => DB::table($table)->delete(), 'append-only');
        }
        // A table another table references is refused by PostgreSQL itself unless truncated together with it; together, only the guard is in the way.
        foreach (['job_assignment_events', 'job_operation_receipts', 'job_holds, job_hold_resolutions', 'job_events, job_assignment_events, job_holds, job_hold_resolutions, job_operation_receipts'] as $set) {
            $this->assertRefused(fn () => DB::statement("TRUNCATE TABLE {$set}"), 'append-only');
        }
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE service_jobs CASCADE'), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE users CASCADE'), 'append-only');

        $guards = DB::select("select c.relname as tbl, p.proname as fn, (t.tgtype & 32) <> 0 as is_truncate from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and c.relname in ('job_assignment_events','job_holds','job_hold_resolutions','job_operation_receipts') and p.proname = 'job_evidence_append_only_guard'");
        $this->assertCount(8, $guards, 'a row guard and a truncate guard on each new table');
        $this->assertCount(4, array_filter($guards, fn ($g) => (bool) $g->is_truncate));
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from pg_trigger t join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and p.proname = 'documents_append_only_guard' and t.tgrelid::regclass::text like 'job%'")->c);
    }

    public function test_a_coherent_chain_commits_in_every_legal_insertion_order(): void
    {
        $user = $this->worker->id;
        // The helpers write event, child, receipt, then the head.
        $this->assertCommits(function () use ($user): void {
            $job = $this->newJob('ORDER-1');
            $this->assign($job, $user);
            $placed = $this->placeHold($job);
            $this->resolveHold($job, $placed['hold']);
            $this->start($job);
        });
        $this->assertSame(['ACTIVE', 5], [DB::table('service_jobs')->where('code', 'ORDER-1')->value('state'), (int) DB::table('service_jobs')->where('code', 'ORDER-1')->value('generation')]);

        // Head first, then event, receipt and child.
        $this->assertCommits(function () use ($user): void {
            $job = $this->newJob('ORDER-2');
            $last = $this->latest($job);
            DB::table('service_jobs')->where('id', $job)->update(['generation' => 2]);
            $eventId = $this->appendEvent($job, 'ASSIGN');
            $this->receipt($job, $eventId);
            DB::table('job_assignment_events')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $eventId, 'sequence' => 1, 'responsible_user_id' => $user, 'actor_id' => $this->user->id,
                'occurrence_precision' => 'EXACT', 'reason' => 'because', 'recorded_at' => $this->at($last->sequence + 1)]);
        });
        $this->assertSame(2, (int) DB::table('service_jobs')->where('code', 'ORDER-2')->value('generation'));
    }

    public function test_the_head_and_the_event_chain_must_agree_at_commit(): void
    {
        // A head with no event at all.
        $this->assertRefused(fn () => DB::table('service_jobs')->insert(['company_id' => 1, 'code' => 'NOEVENT', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]), 'has no lifecycle event');

        // An event the head did not follow, and a head advance with no event.
        $this->assertRefused(function (): void {
            $job = $this->newJob('UNPAIRED-EVENT');
            $this->settle();
            $this->start0($job);
        }, 'differs from its latest event');
        $job = $this->newJob('UNPAIRED-HEAD');
        $this->settle();
        $this->assertRefused(fn () => DB::table('service_jobs')->where('id', $job)->update(['generation' => 2]), 'differs from its latest event');
        $this->assertRefused(fn () => DB::table('service_jobs')->where('id', $job)->update(['state' => 'ACTIVE']), 'differs from its latest event');

        // A first event that is not the initial release.
        foreach ([['sequence' => 2], ['prior_generation' => 1, 'next_generation' => 2], ['from_state' => 'RELEASED'], ['to_state' => 'ACTIVE'], ['operation' => 'HOLD']] as $bad) {
            $this->assertRefused(function () use ($bad): void {
                $j = DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'BADFIRST-'.Str::random(5), 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $j, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0,
                    'next_generation' => 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(0), 'actor_id' => $this->user->id, 'evidence_version' => 1,
                    'command_identity' => (string) Str::uuid(), 'semantic_intent_hash' => str_repeat('b', 64), ...$bad]);
            }, 'Job');
        }
    }

    /** An event appended with no head advance (the head still says generation 1). */
    private function start0(int $job): void
    {
        $this->appendEvent($job, 'HOLD');
    }

    public function test_a_sequence_gap_a_stale_generation_and_a_wrong_state_are_refused(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $this->settle();
        $last = $this->latest($job);

        // Gap: sequence 4 with no 3 (head moved to match, so only the chain is wrong).
        $this->assertRefused(function () use ($job, $last): void {
            $id = $this->appendEvent($job, 'HOLD', ['sequence' => $last->sequence + 2]);
            $this->receipt($job, $id);
            DB::table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'scope_kind' => 'JOB', 'reason' => 'because', 'effective_from' => $this->at($last->sequence + 1), 'actor_id' => $this->user->id, 'recorded_at' => $this->at($last->sequence + 1)]);
            DB::table('service_jobs')->where('id', $job)->update(['generation' => $last->next_generation + 1]);
        }, 'has no predecessor');

        // Stale generation, a skipped increment, a wrong from_state, a state change that is not START.
        foreach ([['prior_generation' => 1, 'next_generation' => 2], ['next_generation' => 9], ['from_state' => 'ACTIVE', 'to_state' => 'ACTIVE'], ['to_state' => 'ACTIVE']] as $bad) {
            $this->assertRefused(function () use ($job, $bad): void {
                $id = $this->appendEvent($job, 'HOLD', $bad);
                $this->receipt($job, $id);
                $event = DB::table('job_events')->find($id);
                DB::table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'scope_kind' => 'JOB', 'reason' => 'because', 'effective_from' => $event->recorded_at, 'actor_id' => $this->user->id, 'recorded_at' => $event->recorded_at]);
                $this->advanceHead($job);
            }, 'job_events_generation_unique|does not continue|retains the RELEASED or ACTIVE state');
        }
        // A second RELEASE is never legal, and START cannot repeat.
        $this->assertRefused(function () use ($job): void {
            $id = $this->appendEvent($job, 'RELEASE');
            $this->receipt0($job, $id);
            $this->advanceHead($job);
        }, 'Job');
    }

    private function receipt0(int $job, int $id): void
    {
        DB::table('job_operation_receipts')->insert(['company_id' => 1, 'job_id' => $job, 'actor_id' => $this->user->id, 'request_key' => 'r-'.$id, 'operation' => 'ASSIGN', 'intent_hash' => str_repeat('c', 64),
            'result_event_id' => $id, 'contract_version' => 1, 'recorded_at' => $this->at(30)]);
    }

    public function test_unknown_and_premature_operations_are_refused_by_direct_sql(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $this->settle();
        foreach (['COMPLETE', 'CLOSE', 'TERMINATE', 'WITHDRAW', 'CANCEL', 'REOPEN', 'release', 'START ', ''] as $operation) {
            $this->assertRefused(fn () => $this->appendEvent($job, $operation, ['to_state' => $operation === '' ? 'RELEASED' : 'COMPLETED']), 'is not allowed by the lifecycle foundation');
        }
        // There is no generic head-state bypass: COMPLETED/CLOSED cannot be reached through any allowed operation.
        foreach (['COMPLETED', 'CLOSED'] as $state) {
            $this->assertRefused(function () use ($job, $state): void {
                $id = $this->appendEvent($job, 'HOLD', ['to_state' => $state]);
                $this->receipt($job, $id);
                DB::table('service_jobs')->where('id', $job)->update(['state' => $state, 'generation' => $this->latest($job)->next_generation]);
            }, 'retains the RELEASED or ACTIVE state');
        }
        // An allowed operation still needs its own command identity, and START needs a responsibility and no unresolved hold.
        $this->assertRefused(fn () => $this->appendEvent($job, 'START', ['command_identity' => null, 'semantic_intent_hash' => null]), 'needs a command identity');
        $bare = $this->newJob('BARE');
        $this->settle();
        $this->assertRefused(function () use ($bare): void {
            $this->start($bare);
        }, 'cannot start without a recorded responsibility');
    }

    public function test_assignments_form_a_continuous_chain_with_a_valid_window_and_one_actor(): void
    {
        $job = $this->newJob();
        $first = $this->assign($job, $this->worker->id);
        $this->settle();
        $firstRow = DB::table('job_assignment_events')->where('event_id', $first)->first();

        // A fork: a second assignment naming the same predecessor, or sequence 1 again.
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 1, 'predecessor_id' => null]), 'job_assignment_events_sequence_unique');
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 3, 'predecessor_id' => $firstRow->id]), 'must continue the assignment');
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => null]), 'job_assignment_events_shape');
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 1, 'predecessor_id' => $firstRow->id]), 'job_assignment_events_shape|must continue the assignment');
        // A predecessor of another Job.
        $other = $this->newJob('OTHER');
        $otherFirst = DB::table('job_assignment_events')->count();
        $this->assertRefused(function () use ($other, $firstRow): void {
            $this->assign($other, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => $firstRow->id]);
        }, 'job_assignment_events_predecessor_fk|must continue the assignment');
        $this->assertSame($otherFirst, DB::table('job_assignment_events')->count());

        // Windows: until needs from and is not before it.
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => $firstRow->id, 'intended_until' => $this->at(100)]), 'job_assignment_events_shape');
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => $firstRow->id, 'intended_from' => $this->at(100), 'intended_until' => $this->at(99)]), 'job_assignment_events_shape');
        // The child must agree with its event: another actor, a different reason, another precision.
        foreach ([['actor_id' => $this->worker->id], ['reason' => 'something else'], ['occurrence_precision' => 'DATE']] as $bad) {
            $this->assertRefused(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => $firstRow->id, ...$bad]), 'does not agree with its ASSIGN event');
        }
        // A well-formed successor with a window commits.
        $this->assertCommits(fn () => $this->assign($job, $this->user->id, [], ['sequence' => 2, 'predecessor_id' => $firstRow->id, 'intended_from' => $this->at(100), 'intended_until' => $this->at(200), 'instructions' => 'Take over']));
        $this->assertSame(2, DB::table('job_assignment_events')->count());
    }

    public function test_holds_are_independent_and_each_resolution_names_its_own_hold(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $one = $this->placeHold($job);
        $two = $this->placeHold($job);
        $this->settle();

        // Resolving one leaves the other in force: START is refused until both are resolved.
        $this->resolveHold($job, $one['hold']);
        $this->settle();
        $this->assertRefused(fn () => $this->start($job), 'unresolved hold');
        // The hold cannot be resolved twice, and a resolution cannot name the wrong Job's hold.
        $this->assertRefused(fn () => $this->resolveHold($job, $one['hold']), 'job_hold_resolutions_hold_unique');
        $sibling = $this->newJob('SIB');
        $siblingHold = $this->placeHold($sibling);
        $this->settle();
        $this->assertRefused(fn () => $this->resolveHold($job, $siblingHold['hold']), 'job_hold_resolutions_hold_fk');

        $this->resolveHold($job, $two['hold']);
        $this->assertCommits(fn () => $this->start($job));
        $this->assertSame('ACTIVE', DB::table('service_jobs')->where('id', $job)->value('state'));
        $this->assertSame(2, DB::table('job_hold_resolutions')->where('job_id', $job)->count());
    }

    public function test_hold_and_resolution_must_agree_with_their_events_and_the_server_time(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $this->settle();

        // Future-effective or client-chosen times are refused: a hold is effective at the event's recorded server time.
        $this->assertRefused(fn () => $this->placeHold($job, [], ['effective_from' => $this->at(500)]), 'effective at the recorded server time');
        $this->assertRefused(fn () => $this->placeHold($job, [], ['effective_from' => $this->at(-5)]), 'effective at the recorded server time');
        $this->assertRefused(fn () => $this->placeHold($job, [], ['reason' => 'a different reason']), 'does not agree with its HOLD event');
        $this->assertRefused(fn () => $this->placeHold($job, [], ['actor_id' => $this->worker->id]), 'does not agree with its HOLD event');
        $this->assertRefused(fn () => $this->placeHold($job, [], ['scope_kind' => 'ACTIVITY']), 'job_holds_shape');

        $placed = $this->placeHold($job);
        $this->settle();
        $this->assertRefused(fn () => $this->resolveHold($job, $placed['hold'], [], ['resolved_effective_at' => $this->at(900)]), 'effective at the recorded server time');
        // A resolution whose event is recorded before the hold took effect.
        $this->assertRefused(fn () => $this->resolveHold($job, $placed['hold'], ['recorded_at' => $this->at(1)]), 'must come after');
        $this->assertCommits(fn () => $this->resolveHold($job, $placed['hold']));
    }

    public function test_each_lifecycle_event_has_exactly_its_own_child_and_a_receipt_from_its_actor(): void
    {
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $this->settle();

        // An ASSIGN, HOLD or RESOLVE_HOLD with no child, and a child of the wrong operation.
        foreach (['ASSIGN', 'HOLD'] as $operation) {
            $this->assertRefused(function () use ($job, $operation): void {
                $id = $this->appendEvent($job, $operation);
                $this->receipt($job, $id);
                $this->advanceHead($job);
            }, 'does not have exactly its own child evidence');
        }
        $this->assertRefused(function () use ($job): void {
            $id = $this->appendEvent($job, 'START');
            $event = DB::table('job_events')->find($id);
            $this->receipt($job, $id);
            DB::table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'scope_kind' => 'JOB', 'reason' => 'because', 'effective_from' => $event->recorded_at, 'actor_id' => $event->actor_id, 'recorded_at' => $event->recorded_at]);
            $this->advanceHead($job);
        }, 'does not have exactly its own child evidence');
        $this->assertRefused(function () use ($job): void {
            $id = $this->appendEvent($job, 'HOLD');
            $event = DB::table('job_events')->find($id);
            $this->receipt($job, $id);
            DB::table('job_assignment_events')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'sequence' => 2, 'predecessor_id' => DB::table('job_assignment_events')->value('id'),
                'responsible_user_id' => $this->user->id, 'actor_id' => $event->actor_id, 'occurrence_precision' => 'EXACT', 'reason' => 'because', 'recorded_at' => $event->recorded_at]);
            $this->advanceHead($job);
        }, 'does not agree with its ASSIGN event|does not have exactly its own child evidence');

        // The original command carries its receipt from its own actor.
        $this->assertRefused(function () use ($job): void {
            $id = $this->appendEvent($job, 'HOLD');
            $event = DB::table('job_events')->find($id);
            DB::table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'scope_kind' => 'JOB', 'reason' => 'because', 'effective_from' => $event->recorded_at, 'actor_id' => $event->actor_id, 'recorded_at' => $event->recorded_at]);
            $this->advanceHead($job);
        }, 'has no receipt from its own actor');
        $this->assertRefused(function () use ($job): void {
            $id = $this->appendEvent($job, 'HOLD');
            $event = DB::table('job_events')->find($id);
            $this->receipt($job, $id, null, $this->worker->id);
            DB::table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $id, 'scope_kind' => 'JOB', 'reason' => 'because', 'effective_from' => $event->recorded_at, 'actor_id' => $event->actor_id, 'recorded_at' => $event->recorded_at]);
            $this->advanceHead($job);
        }, 'has no receipt from its own actor');
    }

    public function test_receipts_are_immutable_references_with_actor_partitioned_keys(): void
    {
        $job = $this->newJob();
        $event = $this->assign($job, $this->worker->id);
        $this->settle();
        $row = DB::table('job_events')->find($event);
        $receipt = ['company_id' => 1, 'job_id' => $job, 'actor_id' => $this->user->id, 'request_key' => 'same-key', 'operation' => 'ASSIGN', 'intent_hash' => str_repeat('d', 64), 'result_event_id' => $event, 'contract_version' => 1, 'recorded_at' => $row->recorded_at];

        $this->assertCommits(fn () => DB::table('job_operation_receipts')->insert($receipt));
        // A replay by another actor under its own key adds a receipt to the original result; it never needs another event.
        $this->assertCommits(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'actor_id' => $this->worker->id]));
        $this->assertSame(1, DB::table('job_events')->where('job_id', $job)->where('operation', 'ASSIGN')->count());
        // The same actor and key cannot be reused; the key is free for a different actor and in another company.
        $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'request_key' => 'same-key', 'intent_hash' => str_repeat('e', 64)]), 'job_operation_receipts_key_unique');
        // Operation must agree with the result event; a release has none; shape checks.
        $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'request_key' => 'k2', 'operation' => 'HOLD']), 'must name the lifecycle operation of its result event');
        $release = $this->latest($job);
        $releaseEvent = DB::table('job_events')->where('job_id', $job)->where('sequence', 1)->value('id');
        $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'request_key' => 'k3', 'operation' => 'RELEASE', 'result_event_id' => $releaseEvent]), 'must name the lifecycle operation of its result event');
        foreach ([['request_key' => ' '], ['operation' => ''], ['intent_hash' => 'ABC'], ['intent_hash' => strtoupper(str_repeat('a', 64))], ['contract_version' => 0]] as $bad) {
            $this->assertRefused(fn () => DB::table('job_operation_receipts')->insert([...$receipt, 'request_key' => 'k4', ...$bad]), 'job_operation_receipts_shape|must name the lifecycle operation of its result event');
        }
        $this->assertSame($release->id, $this->latest($job)->id);
    }

    public function test_command_identity_is_unique_per_job_and_travels_with_its_hash(): void
    {
        $job = $this->newJob();
        $identity = (string) Str::uuid();
        $this->assertCommits(fn () => $this->assign($job, $this->worker->id, ['command_identity' => $identity]));
        // The same command identity cannot name two events, and the hash is required with it.
        $this->assertRefused(fn () => $this->assign($job, $this->user->id, ['command_identity' => $identity]), 'job_events_command_unique');
        $this->assertRefused(fn () => $this->appendEvent($job, 'HOLD', ['semantic_intent_hash' => null]), 'job_events_identity_shape');
        $this->assertRefused(fn () => $this->appendEvent($job, 'HOLD', ['command_identity' => null]), 'job_events_identity_shape|needs a command identity');
        $this->assertRefused(fn () => $this->appendEvent($job, 'HOLD', ['semantic_intent_hash' => 'NOT-HEX']), 'job_events_identity_shape');
        $this->assertRefused(fn () => $this->appendEvent($job, 'HOLD', ['semantic_intent_hash' => strtoupper(str_repeat('a', 64))]), 'job_events_identity_shape');
        // The same identity in another Job is a different command.
        $other = $this->newJob('OTHER');
        $this->assertCommits(fn () => $this->assign($other, $this->worker->id, ['command_identity' => $identity]));
    }

    public function test_the_head_identity_is_fixed_once_it_has_evidence(): void
    {
        $job = $this->newJob();
        $this->settle();
        foreach ([['code' => 'RENAMED'], ['company_id' => $this->otherCompany], ['id' => 9999999]] as $change) {
            $this->assertRefused(fn () => DB::table('service_jobs')->where('id', $job)->update($change), 'cannot change its company, id or code');
        }
        $this->assertRefused(fn () => DB::table('service_jobs')->where('id', $job)->delete(), 'violates foreign key');
        // A Job with no evidence yet may still be renamed inside its creating transaction.
        $this->assertCommits(function (): void {
            $j = DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'DRAFT-A', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('service_jobs')->where('id', $j)->update(['code' => 'DRAFT-B']);
            DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $j, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0,
                'next_generation' => 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(0), 'actor_id' => $this->user->id, 'evidence_version' => 1]);
        });
    }

    public function test_there_is_still_no_way_in(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Jobs/Actions'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Jobs/routes.php'));
        foreach (glob(base_path('app/Modules/Jobs/Models/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/function\s+(?!casts)\w+\s*\(/', (string) file_get_contents($file), basename($file).' declares no behaviour');
        }
    }
}
