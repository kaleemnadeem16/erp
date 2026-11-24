<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobCommandApiSecurityTest extends TestCase
{
    use BuildsLifecycleChains;
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    protected function actorOf(int $company): int
    {
        return $this->operator->id;
    }

    private int $jobId;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->bootStartedJobFixture();
        $this->jobId = $this->releaseTheService()['job_id'];
        $this->operator = User::factory()->create();
        $this->grantJobDuties($this->operator, ...array_diff($this->jobDuties, ['job.receive']));
        $this->actingAs($this->operator);
    }

    private function payload(array $extra = [], ?int $generation = null): array
    {
        return $extra + ['expected_generation' => $generation ?? (int) DB::table('service_jobs')->where('id', $this->jobId)->value('generation'),
            'request_key' => 'http-'.bin2hex(random_bytes(8)), 'command_identity' => JobLifecycleIntent::newCommandIdentity()];
    }

    private function postCommand(string $path, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/jobs/'.$this->jobId.'/'.$path, $payload);
    }

    private const COMMANDS = ['assign' => 'job.assign', 'start' => 'job.start', 'holds' => 'job.hold', 'resolve-hold' => 'job.hold.resolve',
        'complete' => 'job.complete', 'closeout-assessments' => 'job.close', 'close' => 'job.close'];

    private function denied(int $status = 403): void
    {
        foreach (self::COMMANDS as $path => $duty) {
            $this->postCommand($path, [])->assertStatus($status);
        }
    }

    public function test_each_command_requires_exact_active_duty_and_current_all_scope_before_any_job_lookup(): void
    {
        $this->app['auth']->forgetGuards();
        $this->denied(401);
        $this->actingAs($this->operator);
        foreach ([JobDutyStatus::Specified, JobDutyStatus::Staged] as $status) {
            $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn ($d) => new JobDuty($d, $status, 'op', 'projection', 'gate'), $this->jobDuties)));
            $this->denied();
        }
        $this->activateJobDuties($this->jobDuties);
        foreach (self::COMMANDS as $path => $duty) {
            $this->operator->revokePermissionTo($duty);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $lookups = [];
            DB::listen(function ($query) use (&$lookups): void {
                if (str_contains($query->sql, 'service_jobs')) {
                    $lookups[] = $query->sql;
                }
            });
            $this->postCommand($path, [])->assertForbidden();
            $this->assertSame([], $lookups);
            $this->grantJobDuties($this->operator, $duty);
            foreach (['OWN', 'MY_UNITS'] as $scope) {
                AccessScopeLimit::create(['user_id' => $this->operator->id, 'permission' => $duty, 'scope' => $scope]);
                $this->postCommand($path, [])->assertForbidden();
                AccessScopeLimit::query()->where('user_id', $this->operator->id)->delete();
            }
        }
        foreach (['SUSPENDED', 'INVITED', 'DELETED'] as $status) {
            if ($status === 'DELETED') {
                break;
            }
            DB::table('users')->where('id', $this->operator->id)->update(['status' => $status]);
            $this->denied();
        }
        DB::table('users')->where('id', $this->operator->id)->update(['status' => 'ACTIVE']);
        $this->actingAs(User::factory()->create());
        $this->denied();
    }

    public function test_missing_and_foreign_jobs_have_identical_non_disclosing_404s_and_no_creation_route(): void
    {
        $foreignCompany = DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('service_jobs')->insertGetId(['company_id' => $foreignCompany, 'code' => 'SECRET', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::COMMANDS as $path => $duty) {
            $data = $this->payload(['assignee_id' => $this->worker->id, 'hold_id' => 1, 'assessment_id' => 1, 'statement' => 'Done', 'reason' => 'Wait']);
            $allowed = ['expected_generation', 'request_key'];
            if ($path !== 'closeout-assessments') {
                $allowed[] = 'command_identity';
            }
            $allowed = [...$allowed, ...match ($path) {
                'assign' => ['assignee_id', 'reason'],'holds','start' => ['reason'],'resolve-hold' => ['hold_id', 'reason'],'complete' => ['statement'],'close' => ['assessment_id', 'statement'],default => []
            }];
            $data = array_intersect_key($data, array_flip($allowed));
            $one = $this->postJson('/api/v1/jobs/'.$foreign.'/'.$path, $data)->assertNotFound()->json();
            $two = $this->postJson('/api/v1/jobs/99999999/'.$path, $data)->assertNotFound()->json();
            $this->assertSame($one, $two);
            $this->assertSame(['message' => 'The Job was not found.'], $one);
        }
        $this->postJson('/api/v1/jobs', [])->assertMethodNotAllowed();
    }

    public function test_child_targets_are_scoped_and_unknown_ids_do_not_leak_foreign_records(): void
    {
        $this->postCommand('assign', $this->payload(['assignee_id' => 99999999]))->assertUnprocessable();
        $this->postCommand('resolve-hold', $this->payload(['hold_id' => 99999999, 'reason' => 'Resolve']))->assertUnprocessable()->assertJsonPath('reason_code', 'HOLD_NOT_FOUND');
        // Coherent evidence on another Job never qualifies as this Job's child.
        $other = $this->newJob('OTHER');
        $hold = $this->placeHold($other)['hold'];
        $this->postCommand('resolve-hold', $this->payload(['hold_id' => $hold, 'reason' => 'Resolve']))->assertUnprocessable()->assertJsonPath('reason_code', 'HOLD_NOT_FOUND');
    }

    public function test_authority_revoked_after_request_authorisation_is_rechecked_by_action(): void
    {
        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'service_jobs')) {
                $changed = true;
                $this->operator->revokePermissionTo('job.hold');
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
        $this->postCommand('holds', $this->payload(['reason' => 'Wait']))->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame(1, DB::table('job_events')->count());
    }

    private function secondRealJob(): int
    {
        $f = $this->serviceAcceptance($this->checker, $this->customer->id, tag: '-second');
        $token = app(PreviewHandover::class)($this->checker, $f['acceptance'], $f['inputs'])['preview_token'];
        $plan = app(PrepareHandoverPlan::class)($this->checker, 'prepare-second', $f['acceptance'], $f['inputs'], $token);
        $stage = (int) DB::table('handover_stages as s')->join('handover_allocations as a', 'a.id', '=', 's.allocation_id')->where('a.plan_id', $plan['plan_id'])->value('s.id');
        $this->recordScopedBasis($this->checker, 'basis-second', $f['acceptance'], $f['case']);
        $preview = app(PreviewServiceRelease::class)($this->checker, $stage);

        return app(ReleaseHandoverComponent::class)($this->checker, 'release-second', $stage, (int) $preview['basis_id'], $preview['fingerprint'])['job_id'];
    }

    public function test_foreign_employee_wrong_job_assessment_and_cross_job_request_key_are_refused(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Foreign', 'email' => 'foreign@invalid.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->postCommand('assign', $this->payload(['assignee_id' => $foreign]))->assertUnprocessable();
        $assignment = $this->payload(['assignee_id' => $this->operator->id]);
        $this->postCommand('assign', $assignment)->assertOk();
        $this->postCommand('start', $this->payload())->assertOk();
        $this->postCommand('complete', $this->payload(['statement' => 'Done']))->assertOk();
        $original = $this->jobId;
        $this->jobId = $this->secondRealJob();
        $this->postCommand('assign', $assignment)->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->operator->id]))->assertOk();
        $this->postCommand('start', $this->payload())->assertOk();
        $this->postCommand('complete', $this->payload(['statement' => 'Done']))->assertOk();
        $data = $this->payload();
        unset($data['command_identity']);
        $assessment = $this->postCommand('closeout-assessments', $data)->assertOk()->json('data.assessment_id');
        $this->jobId = $original;
        $this->postCommand('close', $this->payload(['assessment_id' => $assessment]))->assertUnprocessable()->assertJsonPath('reason_code', 'CLOSEOUT_ASSESSMENT_REQUIRED');
        $this->postCommand('closeout-assessments', $data)->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->assertSame(0, DB::table('job_closeouts')->count());
    }

    public function test_current_foreign_and_deleted_actors_cannot_use_any_command(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->where('id', $this->operator->id)->update(['company_id' => $company]);
        $this->denied();
        DB::table('users')->where('id', $this->operator->id)->update(['company_id' => 1]);
        DB::table('users')->where('id', $this->operator->id)->delete();
        $this->denied();
    }

    public function test_replay_requires_current_source_eligibility_and_never_reveals_prior_outcome_on_revocation(): void
    {
        $data = $this->payload(['reason' => 'Wait']);
        $this->postCommand('holds', $data)->assertOk();
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_baseline_components')->where('job_id', $this->jobId)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->postCommand('holds', $data)->assertNotFound()->assertJsonMissingPath('data');
        $this->assertSame(1, DB::table('job_holds')->count());
    }
}
