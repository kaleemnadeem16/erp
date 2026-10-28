<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\ResolveJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Execution\UnavailableJobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\Support\Jobs\FakeExecutionContextVerifier;
use Tests\TestCase;

/**
 * J1 runtime Actions (issue #62) — AssignJobResponsibility, StartJob, PlaceJobHold and ResolveJobHold called directly (no route exists) against the integrated J1-F1
 * schema. The shipped duties are SPECIFIED, so every Action refuses everyone; these tests switch the four duties on in a SYNTHETIC manifest, and use a positive FAKE
 * source verifier that proves local behaviour only. "Settle" runs the deferred COMMIT checks inside the test transaction; JobLifecycleActionCommitTest proves the real COMMIT.
 */
class JobLifecycleActionsTest extends TestCase
{
    use BuildsLifecycleChains;
    use RefreshDatabase;

    private const DUTIES = ['job.assign', 'job.start', 'job.hold', 'job.hold.resolve'];

    private User $user;

    private User $worker;

    private User $suspended;

    private int $otherCompany;

    private int $otherUser;

    private int $keys = 0;

    private FakeExecutionContextVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
        $this->suspended = User::factory()->create();
        $this->suspended->forceFill(['status' => MemberStatus::Suspended])->save();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->activate(self::DUTIES);
        $this->grant($this->user, ...self::DUTIES);
        $this->verifier = new FakeExecutionContextVerifier;
        $this->app->instance(JobExecutionContextVerifier::class, $this->verifier);
    }

    /** @param list<string> $names */
    private function activate(array $names, JobDutyStatus $status = JobDutyStatus::Active): void
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $name): JobDuty => new JobDuty($name, $status, 'op', 'projection', 'gate'), $names)));
    }

    private function grant(User $user, string ...$duties): void
    {
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $user->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function cmd(): string
    {
        return JobLifecycleIntent::newCommandIdentity();
    }

    private function gen(int $job): int
    {
        return (int) DB::table('service_jobs')->where('id', $job)->value('generation');
    }

    private function assign(int $job, ?int $assignee = null, ?string $key = null, ?string $cmd = null, ?User $by = null, ?int $generation = null, array $more = []): JobLifecycleOutcome
    {
        $outcome = app(AssignJobResponsibility::class)->execute($by ?? $this->user, $job, $assignee ?? $this->worker->id, $generation ?? $this->gen($job), $key ?? $this->key(), $cmd ?? $this->cmd(), ...$more);
        $this->settle();

        return $outcome;
    }

    private function start(int $job, ?string $key = null, ?string $cmd = null, ?User $by = null, ?int $generation = null): JobLifecycleOutcome
    {
        $outcome = app(StartJob::class)->execute($by ?? $this->user, $job, $generation ?? $this->gen($job), $key ?? $this->key(), $cmd ?? $this->cmd(), 'Go');
        $this->settle();

        return $outcome;
    }

    private function hold(int $job, ?string $key = null, ?string $cmd = null, ?User $by = null, string $reason = 'Waiting for access'): JobLifecycleOutcome
    {
        $outcome = app(PlaceJobHold::class)->execute($by ?? $this->user, $job, $reason, $this->gen($job), $key ?? $this->key(), $cmd ?? $this->cmd());
        $this->settle();

        return $outcome;
    }

    private function resolve(int $job, int $holdId, ?string $key = null, ?string $cmd = null, ?User $by = null, string $reason = 'Access granted'): JobLifecycleOutcome
    {
        $outcome = app(ResolveJobHold::class)->execute($by ?? $this->user, $job, $holdId, $reason, $this->gen($job), $key ?? $this->key(), $cmd ?? $this->cmd());
        $this->settle();

        return $outcome;
    }

    private function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function snapshot(): array
    {
        return [DB::table('service_jobs')->orderBy('id')->get(['id', 'state', 'generation'])->toArray(), DB::table('job_events')->count(), DB::table('job_assignment_events')->count(),
            DB::table('job_holds')->count(), DB::table('job_hold_resolutions')->count(), DB::table('job_operation_receipts')->count()];
    }

    private function openHold(int $job): int
    {
        return (int) DB::table('job_holds')->where('job_id', $job)->orderByDesc('id')->value('id');
    }

    // ---- authority -----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_shipped_duties_are_specified_so_every_action_refuses_everyone_even_with_hand_made_rows(): void
    {
        $this->app->forgetInstance(JobDutyManifest::class);
        $this->app->bind(JobDutyManifest::class, fn () => JobDutyManifest::shipped());
        $job = $this->newJob();
        $this->settle();
        $before = $this->snapshot();

        foreach ([
            fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'k1', $this->cmd()),
            fn () => app(StartJob::class)->execute($this->user, $job, 1, 'k2', $this->cmd()),
            fn () => app(PlaceJobHold::class)->execute($this->user, $job, 'why', 1, 'k3', $this->cmd()),
            fn () => app(ResolveJobHold::class)->execute($this->user, $job, 1, 'why', 1, 'k4', $this->cmd()),
        ] as $call) {
            try {
                $call();
                $this->fail('A SPECIFIED duty must refuse even a holder with a raw row.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->verifier->calls, 'the source verifier is never reached without authority');
    }

    public function test_authority_is_the_exact_duty_for_a_current_member_by_its_own_all_scope(): void
    {
        $job = $this->newJob();
        $this->settle();
        $before = $this->snapshot();
        $act = fn (User $who) => fn () => app(PlaceJobHold::class)->execute($who, $job, 'why', 1, $this->key(), $this->cmd());

        // Wrong duty: an assigner cannot hold; a holder of an unrelated duty cannot either.
        $assigner = User::factory()->create();
        $this->grant($assigner, 'job.assign');
        $other = User::factory()->create();
        $this->grant($other, 'job.hold.resolve');
        // Restricted scope for THAT duty, with an unrelated unrestricted duty held as well.
        $limited = User::factory()->create();
        $this->grant($limited, 'job.hold', 'job.assign');
        AccessScopeLimit::create(['user_id' => $limited->id, 'permission' => 'job.hold', 'scope' => 'MY_UNITS']);
        // Stale and suspended and other-company actors.
        $stale = User::query()->findOrFail($this->user->id);
        DB::table('users')->where('id', $this->user->id)->update(['status' => 'SUSPENDED']);
        $foreign = User::query()->withoutGlobalScopes()->findOrFail($this->otherUser);
        $this->grant($this->suspended, 'job.hold');

        foreach ([$assigner, $other, $limited, $stale, $this->suspended, $foreign] as $who) {
            try {
                $act($who)();
                $this->fail('Expected an authorisation refusal for '.$who->id);
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($before, $this->snapshot());
        // Revocation is immediate.
        DB::table('users')->where('id', $this->user->id)->update(['status' => 'ACTIVE']);
        $this->user->revokePermissionTo('job.hold');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->expectException(AuthorizationException::class);
        $act($this->user)();
    }

    public function test_capability_absence_is_the_same_refusal_whether_or_not_the_job_exists_and_an_entitled_actor_cannot_tell_absent_from_foreign(): void
    {
        $nobody = User::factory()->create();
        $messages = [];
        foreach ([$this->newJob(), 9999999] as $job) {
            try {
                app(PlaceJobHold::class)->execute($nobody, $job, 'why', 1, $this->key(), $this->cmd());
            } catch (AuthorizationException $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertCount(2, array_unique([...$messages, ...$messages]) === $messages ? $messages : $messages);
        $this->assertSame($messages[0], $messages[1]);

        $foreignJob = DB::table('service_jobs')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'THEIRS', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('JOB_NOT_FOUND', $this->refusal(fn () => $this->hold($foreignJob)));
        $this->assertSame('JOB_NOT_FOUND', $this->refusal(fn () => app(PlaceJobHold::class)->execute($this->user, 9999999, 'why', 1, $this->key(), $this->cmd())));
    }

    // ---- assign --------------------------------------------------------------------------------------------------------------------------------------

    public function test_assignment_appends_the_event_the_child_the_head_and_the_receipt_together(): void
    {
        $job = $this->newJob();
        $this->settle();
        $from = Carbon::parse('2026-11-01T08:00:00Z');
        $until = Carbon::parse('2026-11-02T08:00:00Z');
        $outcome = $this->assign($job, key: 'assign-1', more: ['reason' => 'Dispatch', 'intendedFrom' => $from, 'intendedUntil' => $until, 'instructions' => 'Bring the keys']);

        $this->assertSame([$job, 2, 'ASSIGN', 'RELEASED', 2, false], [$outcome->jobId, $outcome->sequence, $outcome->operation, $outcome->state, $outcome->generation, $outcome->replayed]);
        $event = JobEvent::query()->findOrFail($outcome->eventId);
        $this->assertSame(['RELEASED', 'RELEASED', 1, 2, $this->user->id, 'Dispatch'], [$event->from_state, $event->to_state, $event->prior_generation, $event->next_generation, $event->actor_id, $event->reason]);
        $this->assertNotNull($event->command_identity);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $event->semantic_intent_hash);
        $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [DB::table('job_events')->find($event->id)->recorded_at])->d, 'time is the database clock');
        $child = DB::table('job_assignment_events')->where('event_id', $event->id)->sole();
        $this->assertSame([1, null, $this->worker->id, $this->user->id, 'Bring the keys', 'Dispatch'], [$child->sequence, $child->predecessor_id, $child->responsible_user_id, $child->actor_id, $child->instructions, $child->reason]);
        $this->assertTrue(Carbon::parse($child->intended_from)->equalTo($from) && Carbon::parse($child->intended_until)->equalTo($until));
        $receipt = DB::table('job_operation_receipts')->sole();
        $this->assertSame([$event->id, 'ASSIGN', 'assign-1', $this->user->id, 1], [$receipt->result_event_id, $receipt->operation, $receipt->request_key, $receipt->actor_id, $receipt->contract_version]);
        $this->assertSame(['RELEASED', 2], [DB::table('service_jobs')->find($job)->state, $this->gen($job)]);

        // A successor continues the same chain and grants the new assignee nothing.
        $second = $this->assign($job, $this->user->id);
        $this->assertSame([2, DB::table('job_assignment_events')->where('sequence', 1)->value('id')], [DB::table('job_assignment_events')->where('event_id', $second->eventId)->value('sequence'),
            DB::table('job_assignment_events')->where('event_id', $second->eventId)->value('predecessor_id')]);
        $this->assertFalse($this->worker->can('job.start'));
    }

    public function test_assignment_refuses_ineligible_people_bad_input_stale_versions_and_held_jobs_with_zero_effects(): void
    {
        $job = $this->newJob();
        $this->settle();
        $before = $this->snapshot();

        foreach ([$this->suspended->id, $this->otherUser, 9999999] as $assignee) {
            $this->assertSame('ASSIGNEE_NOT_ELIGIBLE', $this->refusal(fn () => $this->assign($job, $assignee)));
        }
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->assign($job, more: ['intendedUntil' => Carbon::parse('2026-11-02T08:00:00Z')])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->assign($job, more: ['intendedFrom' => Carbon::parse('2026-11-02T08:00:00Z'), 'intendedUntil' => Carbon::parse('2026-11-01T08:00:00Z')])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->assign($job, more: ['instructions' => '   '])));
        $this->assertSame('REQUEST_KEY_REQUIRED', $this->refusal(fn () => $this->assign($job, key: ' ')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->assign($job, cmd: 'not-a-uuid')));
        $this->assertSame('STALE_GENERATION', $this->refusal(fn () => $this->assign($job, generation: 9)));
        $this->assertSame('STALE_VERSION_VECTOR', $this->refusal(fn () => $this->assign($job, more: ['expected' => ['assignment_sequence' => 4]])));
        $this->assertSame('STALE_VERSION_VECTOR', $this->refusal(fn () => $this->assign($job, more: ['expected' => ['open_holds' => [1]]])));
        $this->assertSame('STALE_SOURCE_CONTEXT', $this->refusal(fn () => $this->assign($job, more: ['expected' => ['source_vector' => ['release' => 'v0']]])));
        $this->assertEquals($before, $this->snapshot());

        // A hold blocks ordinary mutation: assignment is refused until it is resolved.
        $this->hold($job);
        $held = $this->snapshot();
        $this->assertSame('JOB_HELD', $this->refusal(fn () => $this->assign($job)));
        $this->assertEquals($held, $this->snapshot());
        $this->resolve($job, $this->openHold($job));
        $this->assertSame(1, $this->assign($job)->sequence - 3, 'and works again afterwards');
    }

    // ---- start ---------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_job_starts_only_with_responsibility_no_hold_and_a_ready_verified_context(): void
    {
        $job = $this->newJob();
        $this->settle();
        $this->assertSame('RESPONSIBILITY_REQUIRED', $this->refusal(fn () => $this->start($job)));

        $this->assign($job);
        $this->hold($job);
        $this->assertSame('JOB_HELD', $this->refusal(fn () => $this->start($job)));
        $this->resolve($job, $this->openHold($job));

        $this->verifier->commandReady = false;
        $before = $this->snapshot();
        $this->assertSame('NOT_READY_TO_START', $this->refusal(fn () => $this->start($job)));
        $this->assertEquals($before, $this->snapshot());
        $this->verifier->commandReady = true;

        $outcome = $this->start($job);
        $this->assertSame(['START', 'ACTIVE', 5], [$outcome->operation, $outcome->state, $outcome->generation]);
        $event = JobEvent::query()->findOrFail($outcome->eventId);
        $this->assertSame(['RELEASED', 'ACTIVE'], [$event->from_state, $event->to_state]);
        $this->assertSame(0, DB::table('job_assignment_events')->where('event_id', $event->id)->count() + DB::table('job_holds')->where('event_id', $event->id)->count(), 'START has no child');
        $this->assertSame('ACTIVE', DB::table('service_jobs')->find($job)->state);
        // A started Job cannot start again, whatever the identity; but responsibility and holds still work on an ACTIVE Job.
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => $this->start($job)));
        $this->assertSame('ACTIVE', $this->assign($job, $this->user->id)->state);
        $this->assertSame('ACTIVE', $this->hold($job)->state);
    }

    // ---- holds ---------------------------------------------------------------------------------------------------------------------------------------

    public function test_holds_are_independent_and_each_resolution_names_one_and_starts_nothing(): void
    {
        $job = $this->newJob();
        $this->assign($job);
        $one = $this->hold($job, reason: 'Permit pending');
        $firstHold = $this->openHold($job);
        $this->hold($job, reason: 'Access not agreed');
        $secondHold = $this->openHold($job);
        $this->assertNotSame($firstHold, $secondHold);
        $this->assertSame(2, DB::table('job_holds')->where('job_id', $job)->count());
        $this->assertSame(['JOB', 'Permit pending'], [DB::table('job_holds')->find($firstHold)->scope_kind, DB::table('job_holds')->find($firstHold)->reason]);
        $this->assertSame($one->eventId, DB::table('job_holds')->find($firstHold)->event_id);
        $this->assertSame(DB::table('job_events')->find($one->eventId)->recorded_at, DB::table('job_holds')->find($firstHold)->effective_from, 'effective at the event\'s database time');

        $resolved = $this->resolve($job, $firstHold);
        $this->assertSame('RESOLVE_HOLD', $resolved->operation);
        $this->assertSame('RELEASED', $resolved->state, 'no implicit start');
        $this->assertSame([$secondHold], $this->openHolds($job));
        $this->assertSame('JOB_HELD', $this->refusal(fn () => $this->start($job)));
        $this->assertSame('HOLD_ALREADY_RESOLVED', $this->refusal(fn () => $this->resolve($job, $firstHold)));
        $this->resolve($job, $secondHold);
        $this->assertSame([], $this->openHolds($job));
        $this->assertSame('ACTIVE', $this->start($job)->state);
    }

    /** @return list<int> */
    private function openHolds(int $job): array
    {
        return DB::table('job_holds')->where('job_id', $job)->whereNotIn('id', DB::table('job_hold_resolutions')->where('job_id', $job)->select('hold_id'))->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_hold_and_resolution_inputs_are_validated(): void
    {
        $job = $this->newJob();
        $other = $this->newJob('OTHER');
        $this->settle();
        $this->hold($other);
        $foreignHold = $this->openHold($other);
        $this->hold($job);
        $mine = $this->openHold($job);
        $before = $this->snapshot();

        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->hold($job, reason: '  ')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->resolve($job, $mine, reason: '  ')));
        $this->assertSame('HOLD_NOT_FOUND', $this->refusal(fn () => $this->resolve($job, $foreignHold)), 'a hold of another Job');
        $this->assertSame('HOLD_NOT_FOUND', $this->refusal(fn () => $this->resolve($job, 9999999)));
        $this->assertSame('STALE_GENERATION', $this->refusal(fn () => app(ResolveJobHold::class)->execute($this->user, $job, $mine, 'ok', 99, $this->key(), $this->cmd())));
        $this->assertEquals($before, $this->snapshot());
        // Only the resolver duty resolves: holding job.hold, or having placed the hold, is not enough.
        $placer = User::factory()->create();
        $this->grant($placer, 'job.hold');
        try {
            app(ResolveJobHold::class)->execute($placer, $job, $mine, 'ok', $this->gen($job), $this->key(), $this->cmd());
            $this->fail('job.hold does not imply job.hold.resolve');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- the source verifier -------------------------------------------------------------------------------------------------------------------------

    public function test_the_delivered_verifier_proves_nothing_so_new_work_refuses_but_a_valid_replay_does_not_need_it(): void
    {
        $job = $this->newJob();
        $first = $this->assign($job, key: 'k-assign', cmd: '6f1d2c3e-1111-4222-8333-444455556666');
        $this->app->instance(JobExecutionContextVerifier::class, new UnavailableJobExecutionContextVerifier);
        $this->assertInstanceOf(UnavailableJobExecutionContextVerifier::class, app(JobExecutionContextVerifier::class));
        $before = $this->snapshot();

        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => $this->hold($job)));
        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => $this->assign($job, $this->user->id)));
        $this->assertEquals($before, $this->snapshot(), 'nothing was written');
        // The original key still replays the original event, without the verifier.
        $replay = app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'k-assign', '6f1d2c3e-1111-4222-8333-444455556666');
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->eventId, $replay->eventId);
        // The default binding of a fresh application is the fail-closed one.
        $this->app->forgetInstance(JobExecutionContextVerifier::class);
        $this->assertInstanceOf(UnavailableJobExecutionContextVerifier::class, $this->app->make(JobExecutionContextVerifier::class));
    }

    public function test_an_ineligible_record_is_not_found_and_the_source_is_entered_first_inside_the_transaction(): void
    {
        $job = $this->newJob();
        $this->settle();
        $this->verifier->recordEligible = false;
        $this->assertSame('JOB_NOT_FOUND', $this->refusal(fn () => $this->hold($job)));
        $this->verifier->recordEligible = true;
        $this->hold($job);
        $this->assertSame([['operation' => 'HOLD', 'in_transaction' => true], ['operation' => 'HOLD', 'in_transaction' => true]], $this->verifier->calls, 'entered once per attempt, on the transaction\'s connection');
    }

    // ---- replay and identity -------------------------------------------------------------------------------------------------------------------------

    public function test_the_same_key_and_intent_replay_the_original_even_after_the_head_has_moved_and_other_requests_conflict(): void
    {
        $job = $this->newJob();
        $cmd = $this->cmd();
        $first = $this->assign($job, key: 'k1', cmd: $cmd, generation: 1);
        $this->hold($job);
        $this->resolve($job, $this->openHold($job));
        $count = $this->snapshot();

        $replay = app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'k1', $cmd);
        $this->assertTrue($replay->replayed);
        $this->assertSame([$first->eventId, $first->generation], [$replay->eventId, $replay->generation], 'the original event, although the head is now at 4');
        $this->assertEquals($count, $this->snapshot(), 'no write at all');

        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->user->id, 1, 'k1', $cmd)));
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'k1', $this->cmd())));
        $this->assertEquals($count, $this->snapshot());
    }

    public function test_a_stable_command_identity_resolves_one_event_through_a_fresh_key_or_another_actor_and_a_changed_intent_conflicts(): void
    {
        $job = $this->newJob();
        $cmd = $this->cmd();
        $first = $this->assign($job, key: 'k1', cmd: $cmd, generation: 1);
        $events = DB::table('job_events')->count();

        // A fresh key, same actor, same intent: the same event, plus only the new key's receipt.
        $again = app(AssignJobResponsibility::class)->execute($this->user, $job, $this->worker->id, 1, 'k2', $cmd);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->eventId, $again->eventId);
        $this->assertSame([$events, 2], [DB::table('job_events')->count(), DB::table('job_operation_receipts')->count()]);

        // Another currently authorised actor gets its own receipt and no new event.
        $second = User::factory()->create();
        $this->grant($second, ...self::DUTIES);
        $cross = app(AssignJobResponsibility::class)->execute($second, $job, $this->worker->id, 1, 'k3', $cmd);
        $this->assertTrue($cross->replayed);
        $this->assertSame($first->eventId, $cross->eventId);
        $this->assertSame([$events, 3], [DB::table('job_events')->count(), DB::table('job_operation_receipts')->count()]);
        $this->assertSame($second->id, DB::table('job_operation_receipts')->where('request_key', 'k3')->value('actor_id'));
        $this->settle();

        // Same identity, changed intent: a conflict, not a second effect.
        $this->assertSame('COMMAND_CONFLICT', $this->refusal(fn () => app(AssignJobResponsibility::class)->execute($this->user, $job, $this->user->id, 1, 'k4', $cmd)));
        // A revoked actor gets no replay.
        $second->revokePermissionTo('job.assign');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            app(AssignJobResponsibility::class)->execute($second, $job, $this->worker->id, 1, 'k3', $cmd);
            $this->fail('A revoked actor must not replay.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        // A legitimate repeated hold is a NEW command identity.
        $this->hold($job);
        $this->hold($job, reason: 'Waiting for access');
        $this->assertSame(2, DB::table('job_holds')->where('job_id', $job)->count());
    }

    public function test_results_and_receipts_carry_no_private_payload_or_hash(): void
    {
        $job = $this->newJob();
        $outcome = $this->assign($job);
        $this->assertSame(['jobId', 'eventId', 'sequence', 'operation', 'state', 'generation', 'replayed'], array_map(fn ($p) => $p->getName(), (new \ReflectionClass($outcome))->getProperties()));
        $columns = Schema::getColumnListing('job_operation_receipts');
        $this->assertSame([], array_values(array_filter($columns, fn ($c) => preg_match('/payload|response|result_json|cache|body/', $c) === 1)), 'no response payload is cached');
    }

    // ---- no way in -----------------------------------------------------------------------------------------------------------------------------------

    public function test_nothing_is_routed_registered_granted_or_migrated(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
        $this->assertSame([], array_values(array_filter(app(PermissionRegistry::class)->all(), fn ($p) => str_starts_with($p, 'job.'))));
        $this->assertSame(0, Permission::query()->where('name', 'like', 'job.%')->whereNotIn('name', self::DUTIES)->count());
        $this->assertFileDoesNotExist(base_path('app/Modules/Jobs/routes.php'));
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Jobs/Http'));
        $this->assertSame(['AssignJobResponsibility.php', 'PlaceJobHold.php', 'ResolveJobHold.php', 'StartJob.php'], array_map('basename', glob(base_path('app/Modules/Jobs/Actions/*.php')) ?: []));
        $jobsMigrations = array_map('basename', glob(base_path('database/migrations/*job*')) ?: []);
        $this->assertSame(['2026_10_22_090000_create_jobs_foundation.php', '2026_10_26_090000_create_job_lifecycle_foundation.php'], array_values(array_filter($jobsMigrations, fn ($m) => ! str_contains($m, '0001_01_01'))), 'this milestone adds no migration');
    }
}
