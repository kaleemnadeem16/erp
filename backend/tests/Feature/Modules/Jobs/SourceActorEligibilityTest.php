<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\ResolveJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * #129 — the Sales source verifier proves CURRENT, ACTOR-SPECIFIC owning-source eligibility for each supported Jobs operation through the REAL release, receiver and verifier:
 * the exact Jobs duty (each by its own ALL scope) for an active member of this company, a Job of this company whose lineage is the release that names it, and nothing from
 * Sales read duties — a Jobs-only operator is legitimate. Everything unproved refuses; nothing is granted or activated outside the fixture manifest.
 */
class SourceActorEligibilityTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    private ServiceJob $job;

    private array $released;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
        $this->released = $this->releaseTheService();
        $this->job = ServiceJob::query()->findOrFail($this->released['job_id']);
    }

    private function jobsOnly(string ...$duties): User
    {
        $user = User::factory()->create();
        $this->grantJobDuties($user, ...$duties);

        return $user;
    }

    private function enter(User $actor, ?ServiceJob $job = null, string $operation = 'START'): JobExecutionContext
    {
        $context = null;
        DB::transaction(function () use ($actor, $job, $operation, &$context): void {
            $context = app(JobExecutionContextVerifier::class)->enter($actor, $job ?? $this->job, $operation);
        });

        return $context;
    }

    private function assertRefused(callable $work, string $why): void
    {
        try {
            $work();
            $this->fail("must refuse: {$why}");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_jobs_only_operator_is_eligible_for_each_operation_with_exactly_its_duty_and_no_sales_duty(): void
    {
        foreach (JobLifecycleOperation::DUTIES as $operation => $duties) {
            $operator = $this->jobsOnly(...$duties);
            $this->assertSame([], array_values(array_filter($operator->getAllPermissions()->pluck('name')->all(), fn (string $p): bool => str_starts_with($p, 'sales.'))), 'no Sales duty at all');
            $context = $this->enter($operator, null, $operation);
            $this->assertTrue($context->recordEligible, $operation);
            $this->assertSame((string) $this->released['release_event_id'], $context->sourceVector['release_event'], $operation);
            $this->assertArrayNotHasKey('quotation', $context->sourceVector, 'no private source field is exposed');
        }
    }

    public function test_the_operation_duty_map_is_what_each_action_enforces(): void
    {
        // Each Action passes exactly one duty to the shared orchestration; the verifier's single map must say the same, so neither can widen the other.
        $actions = [
            JobLifecycleOperation::ASSIGN => AssignJobResponsibility::class, JobLifecycleOperation::START => StartJob::class, JobLifecycleOperation::HOLD => PlaceJobHold::class,
            JobLifecycleOperation::RESOLVE_HOLD => ResolveJobHold::class, JobLifecycleOperation::COMPLETE => CompleteJob::class, JobLifecycleOperation::CLOSE => CloseJob::class,
            JobLifecycleOperation::ASSESS_CLOSEOUT => AssessJobCloseout::class,
        ];
        $this->assertSame(array_keys($actions), array_keys(JobLifecycleOperation::DUTIES));
        foreach ($actions as $operation => $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            preg_match_all("/'(job\.[a-z.]+)'/", $source, $m);
            $this->assertSame(JobLifecycleOperation::DUTIES[$operation], array_values(array_unique($m[1])), $operation);
        }
    }

    public function test_the_exact_duty_is_required_for_the_operation_asked_and_no_other_duty_stands_in(): void
    {
        foreach (JobLifecycleOperation::DUTIES as $operation => $duties) {
            $others = array_values(array_diff($this->jobDuties, $duties, ['job.receive']));
            $holder = $this->jobsOnly(...$others);
            $this->assertRefused(fn () => $this->enter($holder, null, $operation), "{$operation} with every duty but its own");
        }
        $this->assertRefused(fn () => $this->enter($this->jobsOnly('job.assign'), null, 'START'), 'assign is not start');
        // The Sales side: the whole Sales vocabulary of the fixture releaser does not make an operator of someone without the Jobs duty.
        $salesOnly = User::factory()->create();
        foreach ($this->handoverDuties() as $duty) {
            $salesOnly->givePermissionTo($duty);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertRefused(fn () => $this->enter($salesOnly), 'Sales duties are not Jobs authority');
    }

    public function test_a_duty_that_is_not_active_a_limited_scope_a_suspended_or_a_foreign_actor_refuses(): void
    {
        $operator = $this->jobsOnly('job.start');
        $this->assertTrue($this->enter($operator)->recordEligible);

        AccessScopeLimit::create(['user_id' => $operator->id, 'permission' => 'job.start', 'scope' => 'MY_UNITS']);
        $this->assertRefused(fn () => $this->enter($operator), 'limited scope is refused, not filtered');
        AccessScopeLimit::query()->where('user_id', $operator->id)->delete();
        $this->assertTrue($this->enter($operator)->recordEligible);

        $stale = User::query()->findOrFail($operator->id);
        DB::table('users')->where('id', $operator->id)->update(['status' => 'SUSPENDED']);
        $this->assertRefused(fn () => $this->enter($stale), 'suspended in the database although the held object says active');
        DB::table('users')->where('id', $operator->id)->update(['status' => 'ACTIVE']);

        $operator->revokePermissionTo('job.start');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertRefused(fn () => $this->enter($stale), 'revoked');

        $this->activateJobDuties(['job.assign']);
        $this->grantJobDuties($operator, 'job.start');
        $this->assertRefused(fn () => $this->enter($operator), 'a duty the manifest does not list as ACTIVE confers nothing');

        $other = (int) DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::query()->withoutGlobalScopes()->findOrFail(DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'F', 'email' => 'f@other.test', 'password' => 'x', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now()]));
        $this->activateJobDuties($this->jobDuties);
        $this->assertRefused(fn () => $this->enter($foreign), 'a member of another company');
    }

    public function test_an_unknown_operation_an_unsupported_origin_and_an_unproved_job_refuse(): void
    {
        $operator = $this->jobsOnly(...$this->jobDuties);
        foreach (['', 'DELETE', 'start'] as $operation) {
            try {
                $this->enter($operator, null, $operation);
                $this->fail("operation [{$operation}] must refuse");
            } catch (DomainInvariantException $e) {
                $this->assertSame(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, $e->reasonCode);
            }
        }

        // A Job with no Sales lineage: never a "no impact" success.
        $raw = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->enter($operator, ServiceJob::query()->findOrFail($raw));
            $this->fail('unsupported origin');
        } catch (DomainInvariantException $e) {
            $this->assertSame(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, $e->reasonCode);
        }

        // Not a stored Job, or a Job held with another company: not eligible, and nothing is revealed about why.
        $this->assertFalse($this->enter($operator, new ServiceJob)->recordEligible);
        $foreignHeld = $this->job->replicate();
        $foreignHeld->id = $this->job->id;
        $foreignHeld->exists = true;
        $foreignHeld->company_id = 999;
        $this->assertFalse($this->enter($operator, $foreignHeld)->recordEligible);
    }

    public function test_a_broken_job_to_source_relationship_is_not_eligible(): void
    {
        $operator = $this->jobsOnly('job.start');
        $this->assertTrue($this->enter($operator)->recordEligible);
        // Corrupt the lineage the way only a bypassed writer could (triggers off for this statement): the component no longer names the locked accepted line.
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_baseline_components')->where('job_id', $this->job->id)->update(['issued_line_id' => (int) DB::table('quotation_issued_lines')->max('id') + 1000]);
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->assertFalse($this->enter($operator)->recordEligible);
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_baseline_components')->where('job_id', $this->job->id)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->assertFalse($this->enter($operator)->recordEligible, 'no component at all');
    }

    public function test_the_company_fence_then_the_source_fences_are_taken_before_any_job_lock_and_the_actor_is_judged_after_them(): void
    {
        $operator = $this->jobsOnly('job.assign');
        $order = [];
        DB::listen(function ($query) use (&$order): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'company_access_generations') && str_contains($sql, 'for share')) {
                $order[] = 'company-fence';
            } elseif (str_contains($sql, 'for update')) {
                $order[] = 'lock:'.(str_contains($sql, '"sales_authority_heads"') ? 'authority-head' : (str_contains($sql, '"service_jobs"') ? 'job' : (str_contains($sql, '"customer_acceptances"') ? 'acceptance' : 'other')));
            } elseif (str_contains($sql, '"model_has_permissions"') || str_contains($sql, '"permissions"')) {
                $order[] = 'permission-read';
            }
        });
        app(AssignJobResponsibility::class)->execute($operator, $this->job->id, $this->worker->id, 1, 'assign-order', JobLifecycleIntent::newCommandIdentity());

        $fence = array_search('company-fence', $order, true);
        $head = array_search('lock:authority-head', $order, true);
        $acceptance = array_search('lock:acceptance', $order, true);
        $job = array_search('lock:job', $order, true);
        $this->assertNotFalse($fence, 'the company access fence was taken: '.implode(' > ', $order));
        $this->assertTrue($fence < $head && $head < $acceptance && $acceptance < $job, 'fence, authority head, acceptance, then the Job: '.implode(' > ', $order));
        $this->assertNotEmpty(array_filter(array_slice($order, $acceptance, $job - $acceptance), fn (string $s): bool => $s === 'permission-read'),
            'the actor duty is read again after the source locks and before the Job lock: '.implode(' > ', $order));
    }

    public function test_replay_completion_and_fulfilment_still_work_for_a_jobs_only_operator(): void
    {
        // The whole lifecycle through the real verifier by operators who hold ONLY their Jobs duty (Sales read duties are not needed), including the identical replay.
        $dispatcher = $this->jobsOnly('job.assign', 'job.start');
        $finisher = $this->jobsOnly('job.complete');
        $identity = JobLifecycleIntent::newCommandIdentity();
        $assigned = app(AssignJobResponsibility::class)->execute($dispatcher, $this->job->id, $dispatcher->id, 1, 'a1', $identity);
        $this->settleConstraints();
        $again = app(AssignJobResponsibility::class)->execute($dispatcher, $this->job->id, $dispatcher->id, 1, 'a1', $identity);
        $this->assertTrue($again->replayed);
        $this->assertSame($assigned->eventId, $again->eventId);
        $started = app(StartJob::class)->execute($dispatcher, $this->job->id, $assigned->generation, 's1', JobLifecycleIntent::newCommandIdentity(), 'Go');
        $this->settleConstraints();
        $done = app(CompleteJob::class)->execute($finisher, $this->job->id, $started->generation, 'c1', JobLifecycleIntent::newCommandIdentity(), 'Delivered.');
        $this->settleConstraints();
        $this->assertSame('COMPLETED', $done->state);
        // The dispatcher cannot complete: the verifier refuses before any write.
        $events = DB::table('job_events')->count();
        $this->assertRefused(fn () => app(CompleteJob::class)->execute($dispatcher, $this->job->id, $done->generation, 'c2', JobLifecycleIntent::newCommandIdentity(), 'Nope.'), 'wrong duty');
        $this->assertSame($events, DB::table('job_events')->count());
    }
}
