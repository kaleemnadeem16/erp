<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\ResolveJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\JobAssignmentEvent;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobOperationReceipt;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\Support\Jobs\FakeExecutionContextVerifier;
use Tests\TestCase;

/**
 * J1 runtime Actions — the REAL COMMIT and real processes. Committed fixtures; the deferred database checks run at an actual COMMIT; a failure after each write leaves
 * nothing; an outer transaction returns no durable success before its own commit; and genuinely separate OS processes (pcntl_fork) race the same Job with duplicate keys, the
 * same command through different keys, and conflicting commands from one generation: one coherent outcome, no fork, no double effect. Wiped by migrate:fresh afterwards.
 */
class JobLifecycleActionCommitTest extends TestCase
{
    use BuildsLifecycleChains;
    use DatabaseMigrations;

    private const DUTIES = ['job.assign', 'job.start', 'job.hold', 'job.hold.resolve'];

    private User $user;

    private User $worker;

    private int $otherUser = 0;

    private int $keys = 0;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            DB::purge('job_probe');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.job_probe' => config('database.connections.testing')]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->user->givePermissionTo(self::DUTIES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), self::DUTIES)));
        $this->app->instance(JobExecutionContextVerifier::class, new FakeExecutionContextVerifier);
    }

    private function cmd(): string
    {
        return JobLifecycleIntent::newCommandIdentity();
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function released(string $code = 'COMMITTED'): int
    {
        // A head alone cannot commit: the head and its RELEASE event go in one real transaction.
        return DB::transaction(fn (): int => $this->newJob($code));
    }

    private function gen(int $job): int
    {
        return (int) DB::table('service_jobs')->where('id', $job)->value('generation');
    }

    private function counts(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['job_events', 'job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_operation_receipts']);
    }

    public function test_every_action_commits_for_real_and_the_chain_is_coherent(): void
    {
        $job = $this->released();
        $assign = app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, $this->key(), $this->cmd(), 'Dispatch');
        $hold = app(PlaceJobHold::class)->execute($this->user, $job, 'Waiting', 2, $this->key(), $this->cmd());
        $holdId = (int) DB::table('job_holds')->value('id');
        app(ResolveJobHold::class)->execute($this->user, $job, $holdId, 'Done', 3, $this->key(), $this->cmd());
        $start = app(StartJob::class)->execute($this->user, $job, 4, $this->key(), $this->cmd());

        $this->assertSame([2, 3, 4, 5], [$assign->generation, $hold->generation, 4, $start->generation]);
        $this->assertSame(['ACTIVE', 5], [DB::table('service_jobs')->find($job)->state, $this->gen($job)]);
        $this->assertSame([5, 1, 1, 1, 4], $this->counts());
        $this->assertSame([1, 2, 3, 4, 5], DB::table('job_events')->where('job_id', $job)->orderBy('sequence')->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(['RELEASE', 'ASSIGN', 'HOLD', 'RESOLVE_HOLD', 'START'], DB::table('job_events')->where('job_id', $job)->orderBy('sequence')->pluck('operation')->all());
    }

    public function test_a_failure_after_any_write_leaves_no_event_child_head_or_receipt(): void
    {
        $job = $this->released();
        $before = $this->counts();
        $hooks = [
            'event' => [JobEvent::class, 'created'],
            'child' => [JobAssignmentEvent::class, 'created'],
            'head' => [ServiceJob::class, 'updated'],
            'receipt' => [JobOperationReceipt::class, 'created'],
        ];
        foreach ($hooks as $label => [$class, $event]) {
            call_user_func([$class, $event], fn () => throw new \RuntimeException("failure after the {$label}"));
            try {
                app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, $this->key(), $this->cmd());
                $this->fail("the {$label} failure must surface");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($label, $e->getMessage());
            } finally {
                // Remove only the listener this loop added; the models' own listeners (company scoping) stay exactly as shipped.
                $class::getEventDispatcher()->forget("eloquent.{$event}: ".$class);
            }
            $this->assertSame($before, $this->counts(), "nothing persisted after the {$label} failure");
            $this->assertSame([1, 'RELEASED'], [$this->gen($job), DB::table('service_jobs')->find($job)->state]);
        }
        // The Job is untouched and still serves the same request.
        $this->assertSame(2, app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, $this->key(), $this->cmd())->generation);
    }

    public function test_an_outer_transaction_returns_no_durable_success_before_its_own_commit(): void
    {
        $job = $this->released();
        DB::beginTransaction();
        try {
            $outcome = app(PlaceJobHold::class)->execute($this->user, $job, 'Waiting', 1, $this->key(), $this->cmd());
            $this->assertSame(2, $outcome->generation, 'the caller sees its own uncommitted work');
            // Another session sees nothing yet.
            $this->assertSame(1, (int) DB::connection('job_probe')->table('job_events')->where('job_id', $job)->count());
            $this->assertSame(1, (int) DB::connection('job_probe')->table('service_jobs')->where('id', $job)->value('generation'));
        } finally {
            DB::commit();
        }
        $this->assertSame([2, 2], [(int) DB::connection('job_probe')->table('job_events')->where('job_id', $job)->count(), (int) DB::connection('job_probe')->table('service_jobs')->where('id', $job)->value('generation')]);
    }

    /**
     * Two genuinely separate OS processes, each on its own connection, released together.
     *
     * @return array{child: array<string, mixed>, parent: array<string, mixed>}
     */
    private function race(Closure $childWork, Closure $parentWork): array
    {
        $resultPath = sys_get_temp_dir().'/job_race_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/job_race_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);
        $outcome = static function (Closure $work): array {
            try {
                $result = $work();

                return ['ok' => true, 'event' => $result->eventId, 'replayed' => $result->replayed];
            } catch (DomainInvariantException $e) {
                return ['ok' => false, 'reason' => $e->reasonCode];
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'ERROR: '.$e->getMessage()];
            }
        };
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'unable to fork');
            if ($pid === 0) {
                config(['database.connections.job_race_child' => config('database.connections.testing'), 'database.default' => 'job_race_child']);
                for ($waited = 0; ! file_exists($startPath) && $waited < 3_000_000; $waited += 1000) {
                    usleep(1000);
                }
                file_put_contents($resultPath, json_encode($outcome($childWork)));
                posix_kill(getmypid(), SIGKILL);
            }
            $default = config('database.default');
            config(['database.connections.job_race_parent' => config('database.connections.testing'), 'database.default' => 'job_race_parent']);
            file_put_contents($startPath, '1');
            try {
                $parent = $outcome($parentWork);
            } finally {
                config(['database.default' => $default]);
                DB::purge('job_race_parent');
            }
            pcntl_waitpid($pid, $status);
            $child = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($child, 'the forked child did not report an outcome');

            return ['child' => $child, 'parent' => $parent];
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
        }
    }

    private function assertCoherent(int $job): void
    {
        $sequences = DB::table('job_events')->where('job_id', $job)->orderBy('sequence')->pluck('sequence')->map(fn ($s) => (int) $s)->all();
        $this->assertSame(range(1, count($sequences)), $sequences, 'one unbroken chain, no fork');
        $this->assertSame(count($sequences), $this->gen($job), 'the head equals the latest event');
    }

    public function test_the_same_key_from_two_processes_produces_one_event_and_both_succeed(): void
    {
        $job = $this->released();
        $cmd = $this->cmd();
        $assign = fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'same-key', $cmd);
        $result = $this->race($assign, $assign);

        $this->assertTrue($result['child']['ok'] && $result['parent']['ok'], json_encode($result));
        $this->assertSame($result['child']['event'], $result['parent']['event']);
        $this->assertSame(1, count(array_filter([$result['child']['replayed'], $result['parent']['replayed']])), 'exactly one created it');
        $this->assertSame([2, 1, 1], [DB::table('job_events')->count(), DB::table('job_assignment_events')->count(), DB::table('job_operation_receipts')->count()]);
        $this->assertCoherent($job);
    }

    public function test_the_same_command_through_two_fresh_keys_produces_one_event(): void
    {
        $job = $this->released();
        $cmd = $this->cmd();
        $make = fn (string $key) => fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, $key, $cmd);
        $result = $this->race($make('key-child'), $make('key-parent'));

        $this->assertTrue($result['child']['ok'] && $result['parent']['ok'], json_encode($result));
        $this->assertSame($result['child']['event'], $result['parent']['event'], 'one business command, one event');
        $this->assertSame([2, 1, 2], [DB::table('job_events')->count(), DB::table('job_assignment_events')->count(), DB::table('job_operation_receipts')->count()], 'each key has its own receipt');
        $this->assertCoherent($job);
    }

    public function test_two_different_commands_from_one_generation_have_exactly_one_winner(): void
    {
        $job = $this->released();
        $assign = fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'assign-key', $this->cmd());
        $hold = fn () => app(PlaceJobHold::class)->execute($this->user, $job, 'Waiting', 1, 'hold-key', $this->cmd());
        $result = $this->race($assign, $hold);

        $outcomes = [$result['child'], $result['parent']];
        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['ok']), 'exactly one command is applied from generation 1: '.json_encode($result));
        $this->assertSame('STALE_GENERATION', array_values(array_filter($outcomes, fn ($o) => ! $o['ok']))[0]['reason']);
        $this->assertSame(2, DB::table('job_events')->count());
        $this->assertSame(1, DB::table('job_assignment_events')->count() + DB::table('job_holds')->count());
        $this->assertCoherent($job);
    }

    public function test_a_start_racing_a_hold_never_leaves_an_active_job_with_a_hold_that_was_placed_before_it(): void
    {
        $job = $this->released();
        app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, $this->key(), $this->cmd());
        $start = fn () => app(StartJob::class)->execute($this->user, $job, 2, 'start-key', $this->cmd());
        $hold = fn () => app(PlaceJobHold::class)->execute($this->user, $job, 'Waiting', 2, 'hold-key', $this->cmd());
        $result = $this->race($start, $hold);

        $outcomes = [$result['child'], $result['parent']];
        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['ok']), 'one of them wins from generation 2: '.json_encode($result));
        $this->assertCoherent($job);
        $state = DB::table('service_jobs')->find($job)->state;
        $holds = DB::table('job_holds')->where('job_id', $job)->count();
        $this->assertTrue(($state === 'ACTIVE' && $holds === 0) || ($state === 'RELEASED' && $holds === 1), "state {$state} with {$holds} holds is one of the two legal outcomes");
    }
}
