<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\TestCase;

/**
 * J1-F1 — the real COMMIT and two real sessions. Committed fixtures and two separate PostgreSQL connections: a surrounding test transaction proves neither the
 * deferred checks' commit timing nor serialisation on the Job head lock. Session A and B write through BEGIN/COMMIT on their own connections.
 */
class JobLifecycleCommitTest extends TestCase
{
    use BuildsLifecycleChains;
    use DatabaseMigrations;

    private User $user;

    private User $worker;

    private string $session = 'testing';

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses lifecycle evidence; this isolated test database is disposable, so wipe and rebuild it.
            DB::purge('life_a');
            DB::purge('life_b');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.life_a' => config('database.connections.testing'), 'database.connections.life_b' => config('database.connections.testing')]);
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
    }

    protected function conn(): Connection
    {
        return DB::connection($this->session);
    }

    private function as(string $session, callable $work): mixed
    {
        $previous = $this->session;
        $this->session = $session;
        try {
            return $work();
        } finally {
            $this->session = $previous;
        }
    }

    private function sql(string $session, string $statement): void
    {
        DB::connection($session)->statement($statement);
    }

    private function assertSqlState(string $state, callable $work): void
    {
        try {
            $work();
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0], $e->getMessage());

            return;
        }
        $this->fail("Expected SQLSTATE {$state}.");
    }

    private function counts(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['service_jobs', 'job_events', 'job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_operation_receipts']);
    }

    /** A committed Job that is RELEASED and has one responsible user, written on the default connection (autocommit). */
    private function committedAssignedJob(string $code = 'COMMITTED'): int
    {
        // A head alone cannot commit, so the whole first chain goes in one real transaction (COMMIT runs the deferred checks).
        return DB::transaction(function () use ($code): int {
            $job = $this->newJob($code);
            $this->assign($job, $this->worker->id);

            return $job;
        });
    }

    public function test_commit_refuses_each_partial_chain_and_nothing_persists(): void
    {
        $job = $this->committedAssignedJob();
        $before = $this->counts();

        // Every step of one HOLD command, omitted in turn: event, child, receipt and head are all required together at COMMIT.
        $steps = ['child' => fn () => null, 'receipt' => fn () => null, 'head' => fn () => null];
        foreach (array_keys($steps) as $omitted) {
            $this->sql('life_a', 'BEGIN');
            $failed = false;
            try {
                $this->as('life_a', function () use ($job, $omitted): void {
                    $eventId = $this->appendEvent($job, 'HOLD');
                    $event = $this->conn()->table('job_events')->find($eventId);
                    if ($omitted !== 'child') {
                        $this->conn()->table('job_holds')->insert(['company_id' => 1, 'job_id' => $job, 'event_id' => $eventId, 'scope_kind' => 'JOB', 'reason' => $event->reason,
                            'effective_from' => $event->recorded_at, 'actor_id' => $event->actor_id, 'recorded_at' => $event->recorded_at]);
                    }
                    if ($omitted !== 'receipt') {
                        $this->receipt($job, $eventId);
                    }
                    if ($omitted !== 'head') {
                        $this->advanceHead($job);
                    }
                });
                $this->sql('life_a', 'COMMIT');
            } catch (QueryException $e) {
                $failed = true;
                $this->assertSame('23514', $e->errorInfo[0], "{$omitted}: ".$e->getMessage());
                $this->sql('life_a', 'ROLLBACK');
            }
            $this->assertTrue($failed, "COMMIT must refuse a chain with the {$omitted} missing");
            $this->assertSame($before, $this->counts(), "nothing persisted after omitting the {$omitted}");
        }

        // The complete command commits.
        $this->sql('life_a', 'BEGIN');
        $this->as('life_a', fn () => $this->placeHold($job));
        $this->sql('life_a', 'COMMIT');
        $this->assertSame(1, DB::table('job_holds')->count());
        $this->assertSame(3, (int) DB::table('service_jobs')->where('id', $job)->value('generation'));
    }

    public function test_a_second_session_waits_on_the_job_head_and_cannot_fork_the_chain(): void
    {
        $job = $this->committedAssignedJob();

        $this->sql('life_a', 'BEGIN');
        $held = $this->as('life_a', fn () => $this->placeHold($job));

        // Session B tries to append the same next sequence while A is uncommitted: it must wait on the Job head, not race past it.
        $this->sql('life_b', 'BEGIN');
        $this->sql('life_b', "SET LOCAL lock_timeout = '300ms'");
        $this->assertSqlState('55P03', fn () => $this->as('life_b', fn () => $this->appendEvent($job, 'HOLD', ['sequence' => 3, 'prior_generation' => 2, 'next_generation' => 3])));
        $this->sql('life_b', 'ROLLBACK');

        $this->sql('life_a', 'COMMIT');

        // Once A has committed, B's stale copy of the same sequence/generation is refused by the keys (no fork), and an honest successor still goes through.
        $this->sql('life_b', 'BEGIN');
        $this->assertSqlState('23505', fn () => $this->as('life_b', fn () => $this->appendEvent($job, 'HOLD', ['sequence' => 3, 'prior_generation' => 2, 'next_generation' => 3])));
        $this->sql('life_b', 'ROLLBACK');
        $this->assertSame(3, (int) DB::table('job_events')->where('job_id', $job)->max('sequence'));
        $this->assertSame(1, DB::table('job_holds')->where('id', $held['hold'])->count());
    }

    public function test_a_start_racing_a_hold_never_commits_over_an_unresolved_hold(): void
    {
        $job = $this->committedAssignedJob();

        // A places a hold and has not committed; B's START waits on the head and is refused outright while A holds it.
        $this->sql('life_a', 'BEGIN');
        $this->as('life_a', fn () => $this->placeHold($job));
        $this->sql('life_b', 'BEGIN');
        $this->sql('life_b', "SET LOCAL lock_timeout = '300ms'");
        $this->assertSqlState('55P03', fn () => $this->as('life_b', fn () => $this->start($job)));
        $this->sql('life_b', 'ROLLBACK');
        $this->sql('life_a', 'COMMIT');

        // After A's commit B sees the hold: START is refused at COMMIT, the Job stays RELEASED.
        $this->sql('life_b', 'BEGIN');
        try {
            $this->as('life_b', fn () => $this->start($job));
            $this->sql('life_b', 'COMMIT');
            $this->fail('A START over an unresolved hold must be refused at COMMIT.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('unresolved hold', $e->getMessage());
            $this->sql('life_b', 'ROLLBACK');
        }
        $this->assertSame('RELEASED', DB::table('service_jobs')->where('id', $job)->value('state'));
        $this->assertSame(0, DB::table('job_events')->where('operation', 'START')->count());
    }

    public function test_the_same_command_identity_cannot_commit_two_events(): void
    {
        $job = $this->committedAssignedJob();
        $identity = (string) Str::uuid();
        $this->sql('life_a', 'BEGIN');
        $this->as('life_a', fn () => $this->placeHold($job, ['command_identity' => $identity]));
        $this->sql('life_a', 'COMMIT');

        $this->sql('life_b', 'BEGIN');
        $this->assertSqlState('23505', fn () => $this->as('life_b', fn () => $this->placeHold($job, ['command_identity' => $identity])));
        $this->sql('life_b', 'ROLLBACK');
        $this->assertSame(1, DB::table('job_holds')->count());
    }

    public function test_a_committed_hold_and_resolution_carry_database_time_whatever_the_writer_supplied(): void
    {
        $job = $this->committedAssignedJob();
        $this->sql('life_a', 'BEGIN');
        $placed = $this->as('life_a', fn () => $this->placeHold($job, ['recorded_at' => '1999-01-01 00:00:00+00'], ['effective_from' => '2099-01-01 00:00:00+00', 'recorded_at' => '1999-01-01 00:00:00+00']));
        $this->sql('life_a', 'COMMIT');
        $this->sql('life_b', 'BEGIN');
        $resolved = $this->as('life_b', fn () => $this->resolveHold($job, $placed['hold'], ['recorded_at' => '2099-01-01 00:00:00+00'], ['resolved_effective_at' => '1999-01-01 00:00:00+00']));
        $this->sql('life_b', 'COMMIT');

        $hold = DB::table('job_holds')->find($placed['hold']);
        $resolution = DB::table('job_hold_resolutions')->where('event_id', $resolved)->first();
        foreach ([DB::table('job_events')->find($placed['event'])->recorded_at, $hold->effective_from, $resolution->resolved_effective_at, DB::table('job_events')->find($resolved)->recorded_at] as $stored) {
            $distance = (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [$stored])->d;
            $this->assertLessThan(60.0, $distance, "the committed time {$stored} is the server's, not the writer's 1999 or 2099");
        }
        $this->assertTrue($resolution->resolved_effective_at >= $hold->effective_from);
    }

    public function test_responsibility_cannot_change_over_an_unresolved_hold_at_commit(): void
    {
        $job = $this->committedAssignedJob();
        $this->sql('life_a', 'BEGIN');
        $this->as('life_a', fn () => $this->placeHold($job));
        $this->sql('life_a', 'COMMIT');

        $this->sql('life_b', 'BEGIN');
        try {
            $this->as('life_b', fn () => $this->assign($job, $this->user->id));
            $this->sql('life_b', 'COMMIT');
            $this->fail('An ASSIGN over an unresolved hold must be refused at COMMIT.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('unresolved hold and cannot assign', $e->getMessage());
            $this->sql('life_b', 'ROLLBACK');
        }
        $this->assertSame(1, DB::table('job_assignment_events')->count());
    }
}
