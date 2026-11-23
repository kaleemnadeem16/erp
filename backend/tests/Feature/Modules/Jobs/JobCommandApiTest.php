<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobCommandApiTest extends TestCase
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

    public function test_real_release_to_close_via_http_records_exactly_one_of_each_evidence(): void
    {
        $this->assertFalse($this->operator->can('job.view'));
        $this->getJson('/api/v1/jobs/'.$this->jobId)->assertForbidden();
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id, 'instructions' => 'Attend site',
            'intended_from' => '2026-11-23T09:00:00+05:00', 'intended_until' => '2026-11-23T10:00:00+05:00']))->assertOk()->assertJsonPath('data.operation', 'ASSIGN');
        $start = $this->payload(['reason' => 'Begin']);
        $this->postCommand('start', $start)->assertOk()->assertJsonPath('data.state', 'ACTIVE');
        $blocked = $this->payload();
        unset($blocked['command_identity']);
        $this->postCommand('closeout-assessments', $blocked)->assertOk()->assertJsonPath('data.status', 'BLOCKED');
        $this->postCommand('holds', $this->payload(['reason' => 'Await access']))->assertOk();
        $hold = DB::table('job_holds')->where('job_id', $this->jobId)->value('id');
        $resolve = $this->payload(['hold_id' => $hold, 'reason' => 'Access restored']);
        $this->postCommand('resolve-hold', $resolve)->assertOk();
        $completion = $this->postCommand('complete', $this->payload(['statement' => 'Whole deliverable completed', 'occurred_at' => '2026-11-23T14:00:00+05:00', 'occurrence_precision' => 'HOUR']))->assertOk();
        $this->assertSame(['job_id', 'event_id', 'sequence', 'operation', 'state', 'generation', 'replayed'], array_keys($completion->json('data')));
        $assessment = $this->payload();
        unset($assessment['command_identity']);
        $ready = $this->postCommand('closeout-assessments', $assessment)->assertOk()->assertJsonPath('data.status', 'READY')->assertJsonPath('data.blockers', []);
        $this->postCommand('close', $this->payload(['assessment_id' => $ready->json('data.assessment_id'), 'statement' => 'Closed']))->assertOk()->assertJsonPath('data.state', 'CLOSED');
        $this->postCommand('start', $start)->assertOk()->assertJsonPath('data.state', 'ACTIVE')->assertJsonPath('data.replayed', true);
        $this->postCommand('resolve-hold', $resolve)->assertOk()->assertJsonPath('data.replayed', true);
        $this->settleConstraints();
        foreach (['job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_completions', 'job_closeouts', 'handover_fulfilment_receipts'] as $table) {
            $this->assertSame(1, DB::table($table)->where('job_id', $this->jobId)->count(), $table);
        }
        $this->assertSame(2, DB::table('job_closeout_assessments')->where('job_id', $this->jobId)->count());
        $this->assertSame(7, DB::table('job_events')->where('job_id', $this->jobId)->count());
        $this->assertSame('09:00:00', substr(DB::table('job_events')->where('operation', 'COMPLETE')->value('occurred_at'), 11, 8));
    }

    public function test_transport_validation_rejects_untrusted_keys_and_invalid_scalars_without_writing(): void
    {
        foreach (['company_id', 'actor_id', 'state', 'generation', 'recorded_at', 'expected', 'source_vector', 'include', 'filter', 'source_pin'] as $key) {
            $this->postCommand('holds', $this->payload(['reason' => 'Wait', $key => 'forged']))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        foreach (['expected_generation' => [0, -1, '9223372036854775808'], 'request_key' => ['', '   ', str_repeat('x', 86)],
            'command_identity' => ['', 'ABCDEFAB-1234-1234-1234-123456789012'], 'occurred_at' => ['2026-11-23', '2026-11-23T09:00:00'],
            'occurrence_precision' => ['', str_repeat('x', 21)], 'reason' => ['', str_repeat('x', 1001)]] as $key => $values) {
            foreach ($values as $value) {
                $this->postCommand('holds', $this->payload(['reason' => 'Wait', $key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
            }
        }
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id, 'intended_until' => '2026-11-23T10:00:00Z']))->assertUnprocessable()->assertJsonValidationErrors('intended_from');
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id, 'intended_from' => '2026-11-23T11:00:00Z', 'intended_until' => '2026-11-23T10:00:00Z']))->assertUnprocessable();
        $this->assertSame(1, DB::table('job_events')->count());
    }

    public function test_business_refusals_retain_stable_422_contract_and_do_not_append_evidence(): void
    {
        $this->postCommand('start', $this->payload())->assertUnprocessable()->assertJsonPath('reason_code', 'RESPONSIBILITY_REQUIRED');
        $this->postCommand('complete', $this->payload(['statement' => 'Done']))->assertUnprocessable()->assertJsonPath('reason_code', 'JOB_STATE_NOT_ALLOWED');
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id], 999))->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_GENERATION');
        $this->postCommand('holds', $this->payload(['reason' => 'Wait']))->assertOk();
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id]))->assertUnprocessable()->assertJsonPath('reason_code', 'JOB_HELD');
        $this->postCommand('start', $this->payload())->assertUnprocessable();
        $this->assertSame(2, DB::table('job_events')->count());
    }

    public function test_ready_assessment_cannot_close_after_fulfilment_or_source_changes(): void
    {
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id]))->assertOk();
        $this->postCommand('start', $this->payload())->assertOk();
        $this->postCommand('complete', $this->payload(['statement' => 'Whole delivery']))->assertOk();
        $assessmentInput = $this->payload();
        unset($assessmentInput['command_identity']);
        $ready = $this->postCommand('closeout-assessments', $assessmentInput)->assertOk()->assertJsonPath('data.status', 'READY')->json('data.assessment_id');
        $receipt = (array) DB::table('handover_fulfilment_receipts')->first();
        foreach (['mismatch', 'missing'] as $change) {
            DB::statement('SET LOCAL session_replication_role = replica');
            if ($change === 'mismatch') {
                DB::table('handover_fulfilment_receipts')->update(['condition_identity' => 'forged']);
            } else {
                DB::table('handover_fulfilment_receipts')->delete();
            }
            DB::statement('SET LOCAL session_replication_role = DEFAULT');
            $this->postCommand('close', $this->payload(['assessment_id' => $ready]))->assertUnprocessable()->assertJsonPath('reason_code', 'CLOSEOUT_BLOCKED');
            $assessmentInput['request_key'] = 'changed-'.$change;
            $this->postCommand('closeout-assessments', $assessmentInput)->assertOk()->assertJsonPath('data.blockers', [$change === 'missing' ? 'FULFILMENT_MISSING' : 'FULFILMENT_MISMATCH']);
            DB::statement('SET LOCAL session_replication_role = replica');
            if ($change === 'missing') {
                DB::table('handover_fulfilment_receipts')->insert($receipt);
            } else {
                DB::table('handover_fulfilment_receipts')->update(['condition_identity' => $receipt['condition_identity']]);
            }
            DB::statement('SET LOCAL session_replication_role = DEFAULT');
        }
        $this->registerSourceFamilies('UNKNOWN');
        $this->postCommand('close', $this->payload(['assessment_id' => $ready]))->assertUnprocessable()->assertJsonPath('reason_code', 'CLOSEOUT_BLOCKED');
        $this->assertSame(0, DB::table('job_closeouts')->count());
        $this->assertSame(4, DB::table('job_events')->count());
    }
}
