<?php

namespace Tests\Support\Jobs;

use App\Models\User;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\ScopedReleaseFixtures;

/**
 * A genuine issued → accepted → prepared → released Sales service in one transactional test, with helpers to take its Job to ACTIVE through the REAL Sales release, receiver and
 * source verifier. The using class needs RefreshDatabase and calls bootStartedJobFixture() from setUp(). Jobs duties are activated through a fixture manifest only (nothing is
 * ACTIVE in production).
 */
trait BuildsStartedSalesJob
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use ScopedReleaseFixtures;

    private array $f;

    private int $stageId;

    private User $worker;

    /** @var list<string> */
    private array $jobDuties = ['job.receive', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.close'];

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    protected function bootStartedJobFixture(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->registerSourceFamilies();
        $this->setUpIssuing();
        $this->activateHandover();
        foreach ($this->handoverDuties() as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $this->activateJobDuties($this->jobDuties);
        $this->grantJobDuties($this->checker, ...$this->jobDuties);
        $this->worker = User::factory()->create();
        $this->f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $token = app(PreviewHandover::class)($this->checker, $this->f['acceptance'], $this->f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'prepare', $this->f['acceptance'], $this->f['inputs'], $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        // The deferred COMMIT-time checks run when a test settles, as at a real commit.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->stageId = (int) DB::table('handover_stages')->value('id');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<string> $names */
    protected function activateJobDuties(array $names): void
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), $names)));
    }

    protected function grantJobDuties(User $user, string ...$duties): void
    {
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $user->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function settleConstraints(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /** @return array{release_event_id:int,job_id:int,job_code:string} */
    protected function releaseTheService(): array
    {
        $this->recordScopedBasis($this->checker, 'basis', $this->f['acceptance'], $this->f['case']);
        $p = app(PreviewServiceRelease::class)($this->checker, $this->stageId);
        $out = app(ReleaseHandoverComponent::class)($this->checker, 'release', $this->stageId, (int) $p['basis_id'], $p['fingerprint']);
        $this->settleConstraints();

        return $out;
    }

    /** A released, assigned and started (ACTIVE) Job; returns the Job at its current generation. */
    protected function startedJob(): ServiceJob
    {
        $out = $this->releaseTheService();
        $job = ServiceJob::query()->findOrFail($out['job_id']);
        $assigned = app(AssignJobResponsibility::class)->execute($this->checker, $job->id, $this->worker->id, 1, 'assign-1', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();
        app(StartJob::class)->execute($this->checker, $job->id, $assigned->generation, 'start-1', JobLifecycleIntent::newCommandIdentity(), 'Go');
        $this->settleConstraints();

        return $job->refresh();
    }
}
