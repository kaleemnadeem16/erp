<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Queries\ReadJobOperationContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobOperationContextTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

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

    private function context(string $operation): TestResponse
    {
        return $this->getJson('/api/v1/jobs/'.$this->jobId.'/operation-context?operation='.$operation);
    }

    public function test_all_seven_profiles_are_minimal_materialised_source_eligible_and_read_only(): void
    {
        $this->postCommand('holds', $this->payload(['reason' => 'Access needed']))->assertOk();
        $hold = (int) DB::table('job_holds')->value('id');
        $before = DB::table('job_events')->count();
        foreach (JobLifecycleOperation::DUTIES as $operation => $duties) {
            $only = User::factory()->create();
            $this->grantJobDuties($only, ...$duties);
            $this->actingAs($only);
            $expected = ['job_id', 'number', 'state', 'generation', 'operation'];
            $expected = [...$expected, ...match ($operation) {
                'ASSIGN' => ['responsibility_id', 'has_responsibility'],'START' => ['has_responsibility', 'open_hold_count'],
                'RESOLVE_HOLD' => ['open_holds'],'COMPLETE' => ['open_hold_count'],'ASSESS_CLOSEOUT','CLOSE' => ['open_hold_count', 'completion_recorded_at'],default => []
            }];
            $response = $this->context($operation)->assertOk();
            $this->assertSame($expected, array_keys($response->json('data')), $operation);
            $direct = app(ReadJobOperationContext::class)->read($only, $this->jobId, $operation);
            $this->assertEquals($response->json('data'), $direct->fields);
            $this->assertFalse($only->can('job.view'));
            if ($operation === 'RESOLVE_HOLD') {
                $this->assertSame($hold, $response->json('data.open_holds.0.id'));
                $this->assertSame(['id', 'reason', 'recorded_at'], array_keys($response->json('data.open_holds.0')));
            }
        }
        $this->assertSame($before, DB::table('job_events')->count());
        $this->assertSame(0, DB::table('job_closeout_assessments')->count());
    }

    public function test_unknown_operation_and_missing_exact_duty_refuse_before_job_lookup(): void
    {
        $this->actingAs(User::factory()->create());
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'service_jobs')) {
                $queries[] = $q->sql;
            }
        });
        foreach (array_keys(JobLifecycleOperation::DUTIES) as $operation) {
            $this->context($operation)->assertForbidden();
        }
        $this->actingAs($this->operator);
        $this->context('UNKNOWN')->assertForbidden();
        $this->assertSame([], $queries);
        foreach (['include=source', 'filter[state]=ACTIVE', 'expected_generation=1'] as $extra) {
            $this->getJson('/api/v1/jobs/'.$this->jobId.'/operation-context?operation=HOLD&'.$extra)->assertUnprocessable();
        }
    }

    public function test_broken_or_unsupported_source_and_missing_jobs_return_only_generic_404(): void
    {
        $missing = $this->getJson('/api/v1/jobs/99999999/operation-context?operation=HOLD')->assertNotFound()->json();
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_baseline_components')->where('job_id', $this->jobId)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->assertSame($missing, $this->context('HOLD')->assertNotFound()->json());
        // Remove the owning lineage as well: the real verifier's SOURCE_CONTEXT_UNAVAILABLE is a non-disclosing record refusal here.
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_sales_origins')->where('job_id', $this->jobId)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $this->assertSame($missing, $this->context('HOLD')->assertNotFound()->json());
    }

    public function test_unready_source_still_allows_eligible_minimal_context_but_completion_is_refused(): void
    {
        $this->registerSourceFamilies('UNKNOWN');
        $this->context('ASSESS_CLOSEOUT')->assertOk()->assertJsonMissingPath('data.source_vector');
        $data = $this->payload();
        unset($data['command_identity']);
        $this->postCommand('closeout-assessments', $data)->assertOk()->assertJsonPath('data.status', 'BLOCKED');
    }

    public function test_real_source_fences_precede_materialisation_with_no_job_lock_and_post_fence_authority_recheck(): void
    {
        $queries = [];
        $changed = false;
        DB::listen(function ($q) use (&$queries, &$changed): void {
            $queries[] = $q->sql;
            if (! $changed && str_contains($q->sql, 'customer_acceptances') && str_contains($q->sql, 'for update')) {
                $changed = true;
                $this->operator->revokePermissionTo('job.hold');
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
        $this->context('HOLD')->assertForbidden()->assertJsonMissingPath('data');
        $this->assertTrue($changed);
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_contains($q, 'jsonb_agg'))), 'no profile query before both authority and source proofs');
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_contains($q, 'service_jobs') && str_contains($q, 'for update'))));
    }

    public function test_unexpected_verifier_failure_is_not_fabricated_into_context_or_not_found(): void
    {
        $real = app(JobExecutionContextVerifier::class);
        $this->app->instance(JobExecutionContextVerifier::class, new class($real) implements JobExecutionContextVerifier
        {
            public function __construct(private JobExecutionContextVerifier $real) {}

            public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
            {
                $this->real->enter($actor, $job, $operation);
                throw new \RuntimeException('infrastructure failure');
            }
        });
        $this->context('HOLD')->assertStatus(500)->assertJsonMissingPath('data');
    }

    public function test_each_context_operation_requires_current_active_membership_and_its_own_all_scope(): void
    {
        foreach (JobLifecycleOperation::DUTIES as $operation => $duties) {
            $duty = $duties[0];
            foreach (['OWN', 'MY_UNITS'] as $scope) {
                AccessScopeLimit::create(['user_id' => $this->operator->id, 'permission' => $duty, 'scope' => $scope]);
                $this->context($operation)->assertForbidden()->assertJsonMissingPath('data');
                AccessScopeLimit::query()->where('user_id', $this->operator->id)->delete();
            }
            $this->operator->revokePermissionTo($duty);
            $this->context($operation)->assertForbidden();
            $this->grantJobDuties($this->operator, $duty);
        }
        foreach (['SUSPENDED', 'INVITED'] as $status) {
            DB::table('users')->where('id', $this->operator->id)->update(['status' => $status]);
            foreach (array_keys(JobLifecycleOperation::DUTIES) as $op) {
                $this->context($op)->assertForbidden();
            }
        }
    }
}
