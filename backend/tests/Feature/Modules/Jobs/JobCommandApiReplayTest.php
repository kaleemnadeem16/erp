<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobCommandApiReplayTest extends TestCase
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

    public function test_key_and_uuid_retries_return_original_result_after_head_advance_and_across_actors(): void
    {
        $data = $this->payload(['reason' => 'Wait']);
        $original = $this->postCommand('holds', $data)->assertOk()->json('data');
        $this->postCommand('holds', $this->payload(['reason' => 'Another hold']))->assertOk();
        foreach ([$data, array_replace($data, ['request_key' => 'fresh-key'])] as $retry) {
            $result = $this->postCommand('holds', $retry)->assertOk()->assertJsonPath('data.replayed', true)->json('data');
            $this->assertSame(array_replace($original, ['replayed' => true]), $result);
        }
        $second = User::factory()->create();
        $this->grantJobDuties($second, 'job.hold');
        $this->actingAs($second);
        $this->postCommand('holds', array_replace($data, ['request_key' => 'second-actor']))->assertOk()->assertJsonPath('data.event_id', $original['event_id'])->assertJsonPath('data.replayed', true);
        $this->assertSame(2, DB::table('job_holds')->count());
        foreach (['reason' => 'Changed', 'expected_generation' => 99, 'occurred_at' => '2026-11-23T12:00:00Z', 'occurrence_precision' => 'DAY'] as $field => $value) {
            $this->postCommand('holds', array_replace($data, ['request_key' => 'conflict-'.$field, $field => $value]))->assertUnprocessable()->assertJsonPath('reason_code', 'COMMAND_CONFLICT');
        }
        $this->actingAs($this->operator);
        $this->postCommand('holds', array_replace($data, ['reason' => 'Changed']))->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->postCommand('start', array_diff_key($data, ['reason' => true]))->assertUnprocessable();
        $this->operator->revokePermissionTo('job.hold');
        $this->postCommand('holds', $data)->assertForbidden();
    }

    public function test_assignment_target_and_generation_are_part_of_stable_intent(): void
    {
        $data = $this->payload(['assignee_id' => $this->worker->id]);
        $this->postCommand('assign', $data)->assertOk();
        $this->postCommand('assign', $data)->assertOk()->assertJsonPath('data.replayed', true);
        foreach (['assignee_id' => $this->operator->id, 'expected_generation' => 2, 'instructions' => 'Different'] as $field => $value) {
            $this->postCommand('assign', array_replace($data, ['request_key' => 'new-'.$field, $field => $value]))->assertUnprocessable()->assertJsonPath('reason_code', 'COMMAND_CONFLICT');
        }
        $this->assertSame(1, DB::table('job_assignment_events')->count());
    }

    public function test_completion_assessment_and_close_replays_never_duplicate_immutable_evidence(): void
    {
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->operator->id]))->assertOk();
        $this->postCommand('start', $this->payload())->assertOk();
        $complete = $this->payload(['statement' => 'Delivered']);
        $this->postCommand('complete', $complete)->assertOk();
        $assess = $this->payload();
        unset($assess['command_identity']);
        $assessment = $this->postCommand('closeout-assessments', $assess)->assertOk()->json('data');
        $this->postCommand('closeout-assessments', $assess)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.assessment_id', $assessment['assessment_id']);
        $this->postCommand('closeout-assessments', array_replace($assess, ['expected_generation' => 99]))->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $second = User::factory()->create();
        $this->grantJobDuties($second, 'job.close');
        $this->actingAs($second);
        $other = $this->postCommand('closeout-assessments', $assess)->assertOk()->assertJsonPath('data.replayed', false)->json('data');
        $this->assertNotSame($assessment['assessment_id'], $other['assessment_id']);
        $this->actingAs($this->operator);
        $close = $this->payload(['assessment_id' => $assessment['assessment_id'], 'statement' => 'Close']);
        $this->postCommand('close', $close)->assertOk();
        $this->postCommand('close', $close)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postCommand('complete', $complete)->assertOk()->assertJsonPath('data.state', 'COMPLETED')->assertJsonPath('data.generation', 4)->assertJsonPath('data.replayed', true);
        foreach (['complete' => [$complete, 'statement', 'Different'], 'close' => [$close, 'statement', 'Different']] as $path => [$data,$field,$value]) {
            $this->postCommand($path, array_replace($data, [$field => $value, 'request_key' => 'changed-'.$path]))->assertUnprocessable()->assertJsonPath('reason_code', 'COMMAND_CONFLICT');
        }
        foreach (['job_completions', 'handover_fulfilment_receipts', 'job_closeouts'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->settleConstraints();
    }
}
