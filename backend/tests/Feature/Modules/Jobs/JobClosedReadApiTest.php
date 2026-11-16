<?php

namespace Tests\Feature\Modules\Jobs;

use App\Http\Resources\UserResource;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobClosedReadApiTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    public function test_real_release_to_close_is_readable_with_safe_evidence_and_paginated_complete_history(): void
    {
        $this->bootStartedJobFixture();
        $this->activateJobDuties([...$this->jobDuties, 'job.view']);
        $this->grantJobDuties($this->checker, 'job.view');
        $job = $this->startedJob();
        $this->actingAs($this->checker);
        $this->getJson('/api/v1/jobs/'.$job->id)->assertOk()->assertJsonPath('data.completion', null)->assertJsonPath('data.closeout', null);
        app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->generation, 'complete-read', JobLifecycleIntent::newCommandIdentity(), 'Delivered in full.', Carbon::parse('2026-11-01T09:00:00Z'), 'SECOND');
        $this->settleConstraints();
        $assessment = app(AssessJobCloseout::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'assess-read');
        app(CloseJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $assessment->assessmentId, 'close-read', JobLifecycleIntent::newCommandIdentity(), 'Operationally closed.');
        $this->settleConstraints();
        $detail = $this->getJson('/api/v1/jobs/'.$job->id)->assertOk()->assertJsonPath('data.state', 'CLOSED')
            ->assertJsonPath('data.completion.statement', 'Delivered in full.')->assertJsonPath('data.closeout.statement', 'Operationally closed.');
        foreach (['completion', 'closeout'] as $field) {
            $operation = $field === 'completion' ? 'COMPLETE' : 'CLOSE';
            $recorded = DB::table('job_events')->where('job_id', $job->id)->where('operation', $operation)->value('recorded_at');
            $this->assertSame(CarbonImmutable::parse($recorded)->utc()->toISOString(), $detail->json('data.'.$field.'.recorded_at'));
            $this->assertEqualsCanonicalizing(['statement', 'recorded_at', 'claimed_occurred_at', 'occurrence_precision'], array_keys($detail->json('data.'.$field)));
        }
        $detail->assertJsonPath('data.completion.claimed_occurred_at', '2026-11-01T09:00:00.000000Z')
            ->assertJsonPath('data.completion.occurrence_precision', 'SECOND')->assertJsonPath('data.closeout.claimed_occurred_at', null);
        $operations = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson('/api/v1/jobs/'.$job->id.'/history?per_page=2&page='.$page)->assertOk()->assertJsonPath('meta.total', 5);
            array_push($operations, ...array_column($response->json('data'), 'operation'));
        }
        $this->assertSame(['RELEASE', 'ASSIGN', 'START', 'COMPLETE', 'CLOSE'], $operations);
        $this->checker->revokePermissionTo('job.view');
        $this->getJson('/api/v1/jobs/'.$job->id.'/history')->assertForbidden();
        $this->getJson('/api/v1/jobs/'.$job->id)->assertForbidden();
    }

    public function test_current_user_permission_projection_hides_inactive_job_view_even_with_a_grant(): void
    {
        $this->bootStartedJobFixture();
        $this->grantJobDuties($this->checker, 'job.view');
        $request = Request::create('/api/v1/user');
        $request->setUserResolver(fn () => $this->checker);
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        $this->assertNotContains('job.view', (new UserResource($this->checker))->resolve($request)['permissions']);
        $this->activateJobDuties(['job.view']);
        $this->assertContains('job.view', (new UserResource($this->checker))->resolve($request)['permissions']);
        AccessScopeLimit::create(['user_id' => $this->checker->id, 'permission' => 'job.view', 'scope' => 'OWN']);
        $this->assertNotContains('job.view', (new UserResource($this->checker))->resolve($request)['permissions']);
        AccessScopeLimit::query()->where('user_id', $this->checker->id)->delete();
        DB::table('users')->where('id', $this->checker->id)->update(['status' => 'SUSPENDED']);
        $this->assertNotContains('job.view', (new UserResource($this->checker))->resolve($request)['permissions']);
    }
}
