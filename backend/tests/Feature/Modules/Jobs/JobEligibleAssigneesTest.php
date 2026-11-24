<?php

namespace Tests\Feature\Modules\Jobs;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Queries\ReadEligibleJobAssignees;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobEligibleAssigneesTest extends TestCase
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
        $this->grantJobDuties($this->operator, 'job.assign');
        $this->actingAs($this->operator);
    }

    private function url(string $query = 'search=Sam'): string
    {
        return '/api/v1/jobs/'.$this->jobId.'/eligible-assignees?'.$query;
    }

    public function test_selector_is_searchable_paged_active_company_only_and_minimal_without_admin_or_view_duties(): void
    {
        foreach (range(1, 45) as $n) {
            User::factory()->create(['name' => sprintf('Sam Employee %02d', $n)]);
        }
        User::factory()->create(['name' => 'Sam suspended', 'status' => 'SUSPENDED']);
        User::factory()->create(['name' => 'Sam invited', 'status' => 'INVITED']);
        $company = DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['company_id' => $company, 'name' => 'Sam foreign', 'email' => 'foreign@invalid.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['job.assign'], $this->operator->getAllPermissions()->pluck('name')->all());
        $first = $this->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 45)->assertJsonPath('meta.per_page', 20);
        $this->assertCount(20, $first->json('data'));
        $this->assertSame(['id', 'name'], array_keys($first->json('data.0')));
        $this->assertSame('Sam Employee 01', $first->json('data.0.name'));
        $this->getJson($this->url('search=Sam&page=3'))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', 'Sam Employee 41');
        $this->getJson($this->url('search=Sam%20Employee%2045'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sam Employee 45');
        $direct = app(ReadEligibleJobAssignees::class)->read($this->operator, $this->jobId, 'Sam Employee 45');
        $this->assertSame('Sam Employee 45', $direct->first()->name);
        $this->assertSame(0, DB::table('job_assignment_events')->count());
    }

    public function test_duty_membership_and_scope_are_rechecked_and_shipped_defaults_never_disclose_choices(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs($this->operator);
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        $this->getJson($this->url())->assertForbidden();
        $this->activateJobDuties($this->jobDuties);
        AccessScopeLimit::create(['user_id' => $this->operator->id, 'permission' => 'job.assign', 'scope' => 'OWN']);
        $this->getJson($this->url())->assertForbidden();
        AccessScopeLimit::query()->where('user_id', $this->operator->id)->delete();
        DB::table('users')->where('id', $this->operator->id)->update(['status' => 'SUSPENDED']);
        $this->getJson($this->url())->assertForbidden();
        DB::table('users')->where('id', $this->operator->id)->update(['status' => 'ACTIVE']);
        $this->operator->revokePermissionTo('job.assign');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_foreign_missing_and_broken_source_jobs_have_identical_generic_refusals_without_employee_search(): void
    {
        $missing = $this->getJson('/api/v1/jobs/99999999/eligible-assignees?search=Sam')->assertNotFound()->json();
        $company = DB::table('companies')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('service_jobs')->insertGetId(['company_id' => $company, 'code' => 'FOREIGN', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($missing, $this->getJson('/api/v1/jobs/'.$foreign.'/eligible-assignees?search=Sam')->assertNotFound()->json());
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('job_baseline_components')->where('job_id', $this->jobId)->delete();
        DB::statement('SET LOCAL session_replication_role = DEFAULT');
        $searches = [];
        DB::listen(function ($query) use (&$searches): void {
            if (str_contains($query->sql, 'ilike')) {
                $searches[] = $query->sql;
            }
        });
        $this->assertSame($missing, $this->getJson($this->url())->assertNotFound()->json());
        $this->assertSame([], $searches);
    }

    public function test_literal_search_bounds_and_unknown_query_keys_are_enforced(): void
    {
        foreach (['search=x', 'per_page=51&search=Sam', 'search=Sam&include=roles', 'search=Sam&status=INVITED', 'search=Sam&ids[]=1'] as $query) {
            $this->getJson($this->url($query))->assertUnprocessable();
        }
        $this->getJson($this->url('search=%25%25'))->assertOk()->assertJsonCount(0, 'data');
        User::factory()->create(['name' => 'Percent %% person']);
        $this->getJson($this->url('search=%25%25'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_post_source_wait_revocation_prevents_the_employee_query(): void
    {
        $changed = false;
        $searches = [];
        DB::listen(function ($query) use (&$changed, &$searches): void {
            if (str_contains($query->sql, 'ilike')) {
                $searches[] = $query->sql;
            }
            if (! $changed && str_contains($query->sql, 'customer_acceptances') && str_contains($query->sql, 'for update')) {
                $changed = true;
                $this->operator->revokePermissionTo('job.assign');
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
        $this->getJson($this->url())->assertForbidden()->assertJsonMissingPath('data');
        $this->assertTrue($changed);
        $this->assertSame([], $searches);
    }

    public function test_effective_user_projection_filters_every_disabled_or_limited_jobs_command_duty(): void
    {
        $this->grantJobDuties($this->operator, 'job.start', 'job.complete', 'job.close');
        $request = Request::create('/api/v1/user');
        $request->setUserResolver(fn () => $this->operator);
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        $this->assertSame([], (new UserResource($this->operator))->resolve($request)['permissions']);
        $this->activateJobDuties($this->jobDuties);
        AccessScopeLimit::create(['user_id' => $this->operator->id, 'permission' => 'job.start', 'scope' => 'OWN']);
        $permissions = (new UserResource($this->operator))->resolve($request)['permissions'];
        $this->assertNotContains('job.start', $permissions);
        $this->assertContains('job.assign', $permissions);
        $this->assertContains('job.complete', $permissions);
        $this->assertContains('job.close', $permissions);
    }
}
