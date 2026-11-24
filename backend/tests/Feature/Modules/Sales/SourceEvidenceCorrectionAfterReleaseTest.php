<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — customer-agreement evidence corrected AFTER the release. Through the REAL release, receiver, Jobs commands and the REAL evidence-correction Action: superseded agreement
 * evidence never supports fresh work (start, complete) or the closeout assessment, while the Job stays readable and holdable, history and receipts are never rewritten, nothing is
 * reopened or cancelled, and an exact retry of an already-recorded command still answers.
 */
class SourceEvidenceCorrectionAfterReleaseTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    protected function handoverDuties(): array
    {
        return [...[P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE], P::CUSTOMER_DECISION_EVIDENCE_RECORD];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
        Permission::findOrCreate(P::CUSTOMER_DECISION_EVIDENCE_RECORD, 'web');
        $this->checker->givePermissionTo(P::CUSTOMER_DECISION_EVIDENCE_RECORD);
        $this->grantJobDuties($this->checker, 'job.hold');
        $this->activateJobDuties([...$this->jobDuties]);
    }

    /** The real correction Action: a later observation naming the head agreement evidence as its predecessor. */
    private function correctAgreement(string $key = 'correct'): int
    {
        $head = (int) DB::table('service_completion_bases')->orderByDesc('version')->value('evidence_id');
        $generation = (int) DB::table('customer_decision_cases')->where('id', $this->f['case'])->value('generation');
        $out = app(AppendCustomerDecisionEvidence::class)($this->checker, $key, $this->f['case'], $generation, [
            'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'Correction: the customer now wants materials supplied by us.',
        ], $head);
        $this->settleConstraints();

        return $out->evidenceId;
    }

    private function refusal(callable $work): string
    {
        try {
            $work();
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        }

        return 'NO_REFUSAL';
    }

    private function released(): ServiceJob
    {
        $out = $this->releaseTheService();

        return ServiceJob::query()->findOrFail($out['job_id']);
    }

    private function assign(ServiceJob $job): int
    {
        $out = app(AssignJobResponsibility::class)->execute($this->checker, $job->id, $this->checker->id, (int) $job->fresh()->generation, 'assign-1', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();

        return $out->generation;
    }

    private function start(ServiceJob $job, string $key = 'start-1', ?string $identity = null): mixed
    {
        $out = app(StartJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $key, $identity ?? JobLifecycleIntent::newCommandIdentity(), 'Go');
        $this->settleConstraints();

        return $out;
    }

    private function complete(ServiceJob $job, string $key = 'complete-1', ?string $identity = null): mixed
    {
        $out = app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $key, $identity ?? JobLifecycleIntent::newCommandIdentity(), 'Delivered in full.');
        $this->settleConstraints();

        return $out;
    }

    private function hold(ServiceJob $job, string $key = 'hold-1'): mixed
    {
        $out = app(PlaceJobHold::class)->execute($this->checker, $job->id, 'Waiting for the customer to restate the agreement', (int) $job->fresh()->generation, $key, JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();

        return $out;
    }

    /** @return array<string,int> the immutable history that must never move because of a correction */
    private function history(): array
    {
        return [
            'release_events' => DB::table('handover_release_events')->count(), 'baselines' => DB::table('job_baselines')->count(), 'components' => DB::table('job_baseline_components')->count(),
            'completions' => DB::table('job_completions')->count(), 'events' => DB::table('job_events')->count(), 'closeouts' => DB::table('job_closeouts')->count(),
            'bases' => DB::table('service_completion_bases')->count(), 'jobs' => DB::table('service_jobs')->count(),
        ];
    }

    public function test_a_correction_before_start_refuses_fresh_work_but_leaves_the_job_and_the_release_alone(): void
    {
        $job = $this->released();
        $this->assign($job);
        $before = $this->history();
        $state = DB::table('service_jobs')->where('id', $job->id)->value('state');

        $this->correctAgreement();

        $this->assertSame('NOT_READY_TO_START', $this->refusal(fn () => $this->start($job)));
        $this->assertSame($state, DB::table('service_jobs')->where('id', $job->id)->value('state'), 'nothing is reopened, cancelled or advanced');
        $this->assertSame($before, $this->history(), 'release, baseline and every receipt are untouched');
        // a protective operation is still supported
        $this->assertSame('HOLD', $this->hold($job)->operation);
    }

    public function test_a_correction_after_start_refuses_completion_but_keeps_the_job_active_and_holdable(): void
    {
        $job = $this->startedJob();
        $before = $this->history();

        $this->correctAgreement();

        $this->assertSame('NOT_READY_TO_COMPLETE', $this->refusal(fn () => $this->complete($job)));
        $this->assertSame('ACTIVE', DB::table('service_jobs')->where('id', $job->id)->value('state'));
        $this->assertSame($before, $this->history());
        $this->assertSame('HOLD', $this->hold($job)->operation);
    }

    public function test_a_correction_after_completion_blocks_closeout_and_keeps_completed_evidence_and_exact_retries(): void
    {
        $job = $this->startedJob();
        $identity = JobLifecycleIntent::newCommandIdentity();
        $done = $this->complete($job, 'complete-1', $identity);
        $before = $this->history();
        $completion = DB::table('job_completions')->first();

        $this->correctAgreement();

        $assessment = app(AssessJobCloseout::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'assess-1');
        $this->settleConstraints();
        $this->assertSame('BLOCKED', $assessment->status);
        $this->assertContains('SOURCE_NOT_READY', $assessment->blockers);
        $this->assertSame('CLOSEOUT_ASSESSMENT_REQUIRED', $this->refusal(fn () => app(CloseJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $assessment->assessmentId, 'close-1', JobLifecycleIntent::newCommandIdentity(), 'Closed.')));

        $this->assertSame('COMPLETED', DB::table('service_jobs')->where('id', $job->id)->value('state'));
        $this->assertEquals($completion, DB::table('job_completions')->first(), 'the completion and its original baseline are never rewritten');
        $after = $this->history();
        $this->assertSame($before['events'], $after['events']);
        $this->assertSame($before['closeouts'], $after['closeouts']);

        // an authorised exact retry of the completed command still answers with the original outcome
        $retry = app(CompleteJob::class)->execute($this->checker, $job->id, $done->generation - 1, 'complete-1', $identity, 'Delivered in full.');
        $this->assertTrue($retry->replayed);
        $this->assertSame($done->eventId, $retry->eventId);
    }

    public function test_a_release_made_before_any_correction_stays_valid_history_and_a_corrected_head_is_the_only_blocker(): void
    {
        $job = $this->startedJob();
        $this->correctAgreement();
        // the corrected evidence is the ONLY reason: a fresh recorded basis on the current evidence would change the pin, which already stops fresh work — nothing re-enables it silently
        $this->assertSame('NOT_READY_TO_COMPLETE', $this->refusal(fn () => $this->complete($job)));
        $this->assertSame(1, DB::table('handover_release_events')->count());
        $this->assertSame(1, DB::table('service_jobs')->count());
    }
}
