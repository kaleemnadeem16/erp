<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\TestCase;

class JobReadApiTest extends TestCase
{
    use BuildsLifecycleChains;
    use RefreshDatabase;

    private User $user;

    private int $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->user = User::factory()->create();
        Permission::findOrCreate('job.view', 'web');
        $this->user->givePermissionTo('job.view');
        $this->active();
    }

    private function active(): void
    {
        $this->manifest(JobDutyStatus::Active);
    }

    private function manifest(JobDutyStatus $status): void
    {
        $duties = array_map(fn (JobDuty $duty): JobDuty => $duty->name === 'job.view'
            ? new JobDuty($duty->name, $status, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, JobDutyManifest::shipped()->all());
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest($duties));
    }

    private function job(string $code = 'JOB-A', string $description = 'Roof survey', int $company = 1): int
    {
        $id = $this->newJob($code, $company);
        DB::table('job_baselines')->insert(['company_id' => $company, 'job_id' => $id, 'version' => 1, 'fingerprint' => 'PRIVATE-HASH',
            'scope_description' => $description, 'completion_rule_version' => 'TEST_V1', 'recorded_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $id;
    }

    private function denied(int $status = 403): void
    {
        foreach (['/jobs', '/jobs/999999', '/jobs/999999/history'] as $path) {
            $this->getJson('/api/v1'.$path)->assertStatus($status);
        }
    }

    public function test_authentication_exact_permission_and_production_defaults_deny_every_endpoint(): void
    {
        $this->denied(401);
        $this->actingAs(User::factory()->create());
        $this->denied();
        $this->actingAs($this->user);
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        $this->denied();
        $this->manifest(JobDutyStatus::Staged);
        $this->denied();
        $this->active();
        $this->user->revokePermissionTo('job.view');
        Permission::findOrCreate('job.close', 'web');
        $this->user->givePermissionTo('job.close');
        $this->denied();
    }

    public function test_current_membership_revocation_and_unsupported_scope_deny_every_endpoint(): void
    {
        $this->actingAs($this->user);
        $this->getJson('/api/v1/jobs')->assertOk();
        $this->user->revokePermissionTo('job.view');
        $this->denied();
        $this->user->givePermissionTo('job.view');
        foreach (['SUSPENDED', 'INVITED'] as $status) {
            DB::table('users')->where('id', $this->user->id)->update(['status' => $status]);
            $this->denied();
        }
        DB::table('users')->where('id', $this->user->id)->update(['status' => 'ACTIVE']);
        foreach (['MY_UNITS', 'OWN'] as $scope) {
            AccessScopeLimit::create(['user_id' => $this->user->id, 'permission' => 'job.view', 'scope' => $scope]);
            $this->denied();
            AccessScopeLimit::query()->where('user_id', $this->user->id)->delete();
        }
    }

    public static function readPaths(): array
    {
        return [['/api/v1/jobs'], ['/api/v1/jobs/999999'], ['/api/v1/jobs/999999/history']];
    }

    #[DataProvider('readPaths')]
    public function test_revocation_after_request_authorisation_is_refused_at_the_fenced_query(string $path): void
    {
        $this->actingAs($this->user);
        $revoked = false;
        $jobQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$revoked, &$jobQueries): void {
            if (str_contains($query->sql, 'service_jobs')) {
                $jobQueries[] = $query->sql;
            }
            if (! $revoked && str_contains($query->sql, 'company_access_generations')) {
                $revoked = true;
                $this->user->revokePermissionTo('job.view');
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
        $this->getJson($path)->assertForbidden();
        $this->assertTrue($revoked, 'The request must reach the decision-time access fence.');
        $this->assertSame([], $jobQueries, 'Revocation must refuse before any Job is looked up.');
    }

    public function test_list_and_detail_are_company_isolated_and_absent_and_foreign_are_identical_404s(): void
    {
        $mine = $this->job();
        $company = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $foreignId = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Foreign', 'email' => 'foreign@api.invalid', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $foreignActor = User::withoutGlobalScopes()->findOrFail($foreignId);
        $this->otherUser = $foreignActor->id;
        $foreign = $this->job('FOREIGN', 'Foreign scope', $company);
        $this->actingAs($this->user);
        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson('/api/v1/jobs/'.$mine)->assertOk()->assertJsonPath('data.id', $mine);
        foreach (['', '/history'] as $suffix) {
            $missing = $this->getJson('/api/v1/jobs/999999'.$suffix)->assertNotFound()->json();
            $this->getJson('/api/v1/jobs/'.$foreign.$suffix)->assertNotFound()->assertExactJson($missing);
        }
        // Even a foreign employee holding a handcrafted permission cannot use the active company.
        $foreignActor->givePermissionTo('job.view');
        $this->actingAs($foreignActor);
        $this->denied();
    }

    public function test_search_filter_sort_pagination_and_safe_detail_and_history(): void
    {
        $a = $this->job();
        $b = $this->job('JOB-B', 'Inspect 100%_literal');
        $c = $this->job('JOB-C');
        $this->assign($a, $this->user->id, ['occurred_at' => '2026-12-01T00:00:00Z']);
        $hold = $this->placeHold($a);
        $this->resolveHold($a, $hold['hold']);
        $this->start($a);
        $this->placeHold($c);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('service_jobs')->update(['created_at' => '2026-11-01T12:00:00Z']);
        $this->actingAs($this->user);
        $this->getJson('/api/v1/jobs?sort=number&per_page=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $a)->assertJsonPath('data.1.id', $b);
        $this->getJson('/api/v1/jobs?sort=number&per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c);
        foreach ([
            ['filter[search]' => '%_'], ['filter[state]' => 'ACTIVE'], ['filter[responsible_member_id]' => $this->user->id],
            ['filter[has_open_hold]' => 'true'], ['filter[created_from]' => '2026-11-01', 'filter[created_until]' => '2026-11-01'],
            ['filter[created_until]' => '2026-11-01'],
        ] as $index => $query) {
            $response = $this->getJson('/api/v1/jobs?'.http_build_query($query))->assertOk();
            $response->assertJsonPath('meta.total', $index >= 4 ? 3 : 1);
        }
        foreach (['number', 'state', 'created_at', 'released_at', 'started_at'] as $sort) {
            $this->getJson('/api/v1/jobs?sort=-'.$sort)->assertOk()->assertJsonCount(3, 'data');
        }
        $detail = $this->getJson('/api/v1/jobs/'.$a)->assertOk()->assertJsonPath('data.assignment.member_id', $this->user->id)->assertJsonMissingPath('data.sales_origin');
        $first = $this->getJson('/api/v1/jobs/'.$a.'/history?per_page=2')->assertOk()->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.operation', 'RELEASE')->assertJsonPath('data.1.operation', 'ASSIGN');
        $this->getJson('/api/v1/jobs/'.$a.'/history?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.sequence', 3)->assertJsonPath('data.1.operation', 'RESOLVE_HOLD');
        $this->getJson('/api/v1/jobs/'.$a.'/history?per_page=2&page=3')->assertOk()->assertJsonPath('data.0.operation', 'START');
        $summaryKeys = ['id', 'number', 'state', 'description', 'responsible_member', 'has_open_hold', 'created_at', 'released_at', 'started_at'];
        $this->assertEqualsCanonicalizing($summaryKeys, array_keys($this->getJson('/api/v1/jobs')->json('data.0')));
        $this->assertEqualsCanonicalizing([...$summaryKeys, 'baseline', 'assignment', 'open_holds', 'default_activity', 'completion', 'closeout'], array_keys($detail->json('data')));
        $this->assertEqualsCanonicalizing(['id', 'sequence', 'operation', 'from_state', 'to_state', 'recorded_at', 'claimed_occurred_at',
            'occurrence_precision', 'actor', 'reason', 'responsible_member_id', 'hold_id'], array_keys($first->json('data.0')));
        foreach (['PRIVATE-HASH', 'fingerprint', 'semantic_intent_hash', 'intent_hash', 'command_identity', 'cost', 'price', 'customer_representative_snapshot', 'evidence_version'] as $secret) {
            $this->assertStringNotContainsString($secret, $detail->getContent().$first->getContent().$this->getJson('/api/v1/jobs')->getContent());
        }
    }

    public function test_out_of_range_or_non_numeric_route_ids_do_not_reach_integer_coercion(): void
    {
        $this->actingAs($this->user);
        foreach (['0', '-1', 'not-a-job', str_repeat('9', 25)] as $id) {
            foreach (['', '/history'] as $suffix) {
                $this->getJson('/api/v1/jobs/'.$id.$suffix)->assertNotFound();
            }
        }
    }

    public function test_only_approved_read_and_command_routes_exist_with_authentication(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/jobs'));
        $this->assertCount(11, $routes);
        $this->assertEqualsCanonicalizing(['api/v1/jobs', 'api/v1/jobs/{job}', 'api/v1/jobs/{job}/history', 'api/v1/jobs/{job}/assign', 'api/v1/jobs/{job}/start', 'api/v1/jobs/{job}/holds', 'api/v1/jobs/{job}/resolve-hold', 'api/v1/jobs/{job}/complete', 'api/v1/jobs/{job}/closeout-assessments', 'api/v1/jobs/{job}/close', 'api/v1/jobs/{job}/operation-context'], $routes->map(fn ($route) => $route->uri())->values()->all());
        foreach ($routes as $route) {
            $this->assertSame(in_array($route->uri(), ['api/v1/jobs', 'api/v1/jobs/{job}', 'api/v1/jobs/{job}/history', 'api/v1/jobs/{job}/operation-context'], true) ? ['GET', 'HEAD'] : ['POST'], $route->methods());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }

    public function test_invalid_input_and_protected_filter_sort_include_are_refused(): void
    {
        $this->actingAs($this->user);
        foreach ([['page' => 0], ['page' => 1000001], ['per_page' => 101], ['sort' => 'cost'], ['include' => 'sales_origin'],
            ['filter[state]' => 'FAKE'], ['filter[search]' => str_repeat('x', 201)], ['filter[responsible_member_id]' => 0],
            ['filter[has_open_hold]' => 'yes'], ['filter[created_from]' => '2026-02-30'], ['filter[price]' => '100'],
            ['filter[created_from]' => '2026-11-02', 'filter[created_until]' => '2026-11-01']] as $query) {
            $this->getJson('/api/v1/jobs?'.http_build_query($query))->assertUnprocessable();
        }
        foreach (['page=0', 'per_page=101', 'sort=-sequence', 'include=actor', 'filter[operation]=RELEASE'] as $query) {
            $this->getJson('/api/v1/jobs/999999/history?'.$query)->assertUnprocessable();
        }
    }
}
