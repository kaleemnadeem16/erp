<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutBlockers;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Sales\Support\Handover\Release\FulfilmentFact;
use App\Modules\Sales\Support\Handover\Release\FulfilmentState;
use App\Modules\Sales\Support\Handover\Release\SalesServiceFulfilment;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use App\Modules\Sales\Support\Handover\Release\ServiceFulfilmentRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — the Sales fulfilment connection, through the REAL Sales release, receiver, source verifier and recorder. Completing a Job writes the Sales-owned immutable fulfilment
 * receipt (the exact agreed deliverable and conditions were fulfilled) in the SAME transaction as the Jobs completion evidence; closeout re-verifies it. Missing, mismatched or
 * stale evidence blocks the close; a failure on either side rolls both back; nothing here touches claims, allocations, capacity or any financial record. Disabled: no duty is ACTIVE.
 */
class JobFulfilmentCloseoutTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    private const EVIDENCE = ['job_events', 'job_completions', 'handover_fulfilment_receipts', 'job_operation_receipts'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
    }

    private function complete(ServiceJob $job, string $key = 'c1', ?string $identity = null, ?User $actor = null, ?int $generation = null): JobLifecycleOutcome
    {
        $out = app(CompleteJob::class)->execute($actor ?? $this->checker, $job->id, $generation ?? (int) $job->fresh()->generation, $key, $identity ?? JobLifecycleIntent::newCommandIdentity(), 'Delivered in full.');
        $this->settleConstraints();

        return $out;
    }

    private function completedJob(): ServiceJob
    {
        $job = $this->startedJob();
        $this->complete($job);

        return $job->refresh();
    }

    private function assess(ServiceJob $job, string $key = 'a1'): JobCloseoutOutcome
    {
        $out = app(AssessJobCloseout::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $key);
        $this->settleConstraints();

        return $out;
    }

    private function close(ServiceJob $job, int $assessmentId, string $key = 'k1'): JobLifecycleOutcome
    {
        $out = app(CloseJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, $assessmentId, $key, JobLifecycleIntent::newCommandIdentity(), 'Closed.');
        $this->settleConstraints();

        return $out;
    }

    private function refusal(callable $work): string
    {
        try {
            $work();
            $this->fail('A refusal was expected.');
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        }
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return array_combine(self::EVIDENCE, array_map(fn (string $t): int => DB::table($t)->count(), self::EVIDENCE));
    }

    /** @return array<string,mixed> the Phase 4 / Sales rows a Job's completion and closeout must never touch */
    private function phaseFourState(): array
    {
        return [
            'claims' => DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all(),
            'acceptances' => DB::table('customer_acceptances')->orderBy('id')->get()->all(),
            'acceptance_lines' => DB::table('customer_acceptance_lines')->orderBy('id')->get()->all(),
            'allocations' => DB::table('handover_allocations')->orderBy('id')->get()->all(),
            'stages' => DB::table('handover_stages')->orderBy('id')->get()->all(),
            'release_events' => DB::table('handover_release_events')->orderBy('id')->get()->all(),
        ];
    }

    /** Runs raw SQL as the database owner would: with the guards switched off, to forge evidence the Actions can never write. */
    private function tamper(callable $work): void
    {
        DB::statement('SET LOCAL session_replication_role = replica');
        try {
            $work();
        } finally {
            DB::statement('SET LOCAL session_replication_role = DEFAULT');
        }
    }

    public function test_a_real_release_completion_and_closeout_carry_one_fulfilment_receipt_and_touch_no_phase_four_row(): void
    {
        $this->assertInstanceOf(SalesServiceFulfilment::class, app(ServiceFulfilmentRecorder::class));
        $job = $this->startedJob();
        $before = $this->phaseFourState();
        $out = $this->complete($job);

        $receipt = DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->first();
        $completion = DB::table('job_completions')->where('job_id', $job->id)->first();
        $origin = DB::table('job_sales_origins')->where('job_id', $job->id)->first();
        $component = DB::table('job_baseline_components')->where('job_id', $job->id)->first();
        $issued = DB::table('quotation_issued_lines')->where('id', $component->issued_line_id)->first();
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count());
        $this->assertSame($out->eventId, (int) $receipt->event_id, 'the receipt names the Job completion event');
        $this->assertSame((int) $completion->event_id, (int) $receipt->event_id);
        $this->assertSame((int) $origin->release_event_id, (int) $receipt->release_event_id, 'it names the real release');
        $this->assertSame((int) $component->issued_line_id, (int) $receipt->issued_line_id, 'it names the accepted, issued deliverable');
        $this->assertSame((int) $component->basis_id, (int) $receipt->basis_id);
        $this->assertSame($component->condition_identity, $receipt->condition_identity, 'it carries the exact agreed conditions');
        $this->assertSame(ScopeHeader::digest((string) $issued->description), $receipt->deliverable_digest, 'it carries the digest of the exact agreed deliverable');
        $this->assertSame($this->checker->id, (int) $receipt->completed_by);
        $this->assertNotNull($receipt->applicability_pin);
        $this->assertSame(FulfilmentState::OK, app(ServiceFulfilmentRecorder::class)->verify($job->id));
        $this->assertEquals($before, $this->phaseFourState(), 'completion changes no claim, allocation, stage or release');

        $assessment = $this->assess($job);
        $this->assertSame('READY', $assessment->status);
        $this->assertSame('CLOSED', $this->close($job, $assessment->assessmentId)->state);
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count(), 'closing writes no second receipt');
        $this->assertEquals($before, $this->phaseFourState(), 'closeout settles nothing and frees no accepted capacity');
    }

    public function test_the_same_key_a_fresh_key_and_a_second_actor_never_create_a_second_receipt(): void
    {
        $job = $this->startedJob();
        $identity = JobLifecycleIntent::newCommandIdentity();
        $first = $this->complete($job, 'c1', $identity);
        $generation = $first->generation;
        $again = app(CompleteJob::class)->execute($this->checker, $job->id, $generation - 1, 'c1', $identity, 'Delivered in full.');
        $fresh = app(CompleteJob::class)->execute($this->checker, $job->id, $generation - 1, 'c2', $identity, 'Delivered in full.');
        $second = User::factory()->create();
        $this->grantJobDuties($second, 'job.complete');
        $other = app(CompleteJob::class)->execute($second, $job->id, $generation - 1, 'c3', $identity, 'Delivered in full.');
        $this->settleConstraints();

        $this->assertTrue($again->replayed);
        $this->assertSame($first->eventId, $fresh->eventId);
        $this->assertSame($first->eventId, $other->eventId);
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->count());
        // A genuinely new command on a completed Job is refused and writes nothing; the recorder itself is idempotent for the same completion.
        $before = $this->counts();
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => app(CompleteJob::class)->execute($this->checker, $job->id, $generation, 'c4', JobLifecycleIntent::newCommandIdentity(), 'Again')));
        $receiptId = (int) DB::table('handover_fulfilment_receipts')->value('id');
        $this->assertSame($receiptId, app(ServiceFulfilmentRecorder::class)->record(new FulfilmentFact($job->id, $first->eventId, $this->checker->id, null)));
        $this->assertSame($before, $this->counts());
    }

    public function test_a_missing_receipt_blocks_the_assessment_and_a_ready_assessment_cannot_close_without_it(): void
    {
        $job = $this->completedJob();
        $ready = $this->assess($job, 'ready');
        $this->assertSame('READY', $ready->status);
        // Forged evidence: the receipt vanishes (only possible with the guards switched off).
        $this->tamper(fn () => DB::table('handover_fulfilment_receipts')->where('job_id', $job->id)->delete());

        $this->assertSame(FulfilmentState::MISSING, app(ServiceFulfilmentRecorder::class)->verify($job->id));
        $blocked = $this->assess($job, 'missing');
        $this->assertSame('BLOCKED', $blocked->status);
        $this->assertSame([JobCloseoutBlockers::FULFILMENT_MISSING], $blocked->blockers);
        $this->assertSame('CLOSEOUT_ASSESSMENT_REQUIRED', $this->refusal(fn () => $this->close($job, $blocked->assessmentId, 'k-blocked')));
        // A READY assessment is never trusted past the moment it was made: the close re-verifies under the Job lock.
        $this->assertSame('CLOSEOUT_BLOCKED', $this->refusal(fn () => $this->close($job, $ready->assessmentId, 'k-late')));
        $this->assertSame('COMPLETED', $job->fresh()->state);
        $this->assertSame(0, DB::table('job_closeouts')->count());
    }

    public function test_a_mismatched_receipt_blocks_closeout_whichever_fact_disagrees(): void
    {
        $job = $this->completedJob();
        $receipt = fn () => DB::table('handover_fulfilment_receipts')->where('job_id', $job->id);
        $original = (array) $receipt()->first();

        foreach ([
            'conditions' => ['condition_identity' => 'forged-conditions'],
            'deliverable text' => ['deliverable_digest' => str_repeat('a', 64)],
            'actor' => ['completed_by' => $this->worker->id],
        ] as $label => $change) {
            $this->tamper(fn () => $receipt()->update($change));
            $this->assertSame(FulfilmentState::MISMATCH, app(ServiceFulfilmentRecorder::class)->verify($job->id), $label);
            $assessment = $this->assess($job, 'm-'.str_replace(' ', '-', $label));
            $this->assertSame('BLOCKED', $assessment->status, $label);
            $this->assertSame([JobCloseoutBlockers::FULFILMENT_MISMATCH], $assessment->blockers, $label);
            $this->tamper(fn () => $receipt()->update(array_intersect_key($original, $change)));
        }
        // The release the receipt names no longer being the Job's: also a mismatch (a receipt of ANOTHER release can never close this Job).
        $this->assertSame(FulfilmentState::OK, app(ServiceFulfilmentRecorder::class)->verify($job->id));
        $this->assertSame('READY', $this->assess($job, 'restored')->status);
    }

    public function test_an_unsupported_origin_and_a_forged_fact_refuse_and_write_nothing(): void
    {
        $job = $this->startedJob();
        $raw = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'ACTIVE', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->counts();
        $recorder = app(ServiceFulfilmentRecorder::class);
        // A Job with no Sales origin has no supported source integration: the recorder refuses (never a "no impact" receipt).
        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => $recorder->record(new FulfilmentFact($raw, 1, $this->checker->id, null))));
        // A fact that does not name this Job's COMPLETE event (here: its RELEASE event) refuses.
        $release = (int) DB::table('job_events')->where('job_id', $job->id)->where('operation', 'RELEASE')->value('id');
        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => $recorder->record(new FulfilmentFact($job->id, $release, $this->checker->id, null))));
        $this->assertSame($before, $this->counts());
        $this->assertSame(FulfilmentState::MISSING, $recorder->verify($raw));
    }

    public function test_permission_revocation_applies_to_the_completion_that_writes_the_receipt_and_to_its_replay(): void
    {
        $job = $this->startedJob();
        $identity = JobLifecycleIntent::newCommandIdentity();
        $this->checker->revokePermissionTo('job.complete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = $this->counts();
        try {
            app(CompleteJob::class)->execute($this->checker->fresh(), $job->id, (int) $job->fresh()->generation, 'r1', $identity, 'x');
            $this->fail('A revoked duty must refuse.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->counts(), 'no evidence and no receipt without the duty');

        $this->grantJobDuties($this->checker, 'job.complete');
        $out = $this->complete($job, 'r2', $identity);
        $this->checker->revokePermissionTo('job.complete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $counts = $this->counts();
        try {
            app(CompleteJob::class)->execute($this->checker->fresh(), $job->id, $out->generation - 1, 'r2', $identity, 'Delivered in full.');
            $this->fail('A revoked duty must refuse even a replay.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($counts, $this->counts());
    }

    public function test_a_failure_on_either_side_rolls_the_completion_and_the_receipt_back_together(): void
    {
        $job = $this->startedJob();
        $before = $this->counts();
        DB::unprepared("CREATE FUNCTION zz_inject_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure on %', TG_TABLE_NAME USING ERRCODE='P0001'; END \$\$");
        // Jobs side (the completion row) and Sales side (the receipt), plus the actor's receipt written last: each failure leaves nothing of the other behind.
        foreach (['job_completions', 'handover_fulfilment_receipts', 'job_operation_receipts'] as $table) {
            DB::unprepared("CREATE TRIGGER zz_inject AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION zz_inject_failure()");
            try {
                app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'inject-'.$table, JobLifecycleIntent::newCommandIdentity(), 'x');
                $this->fail("Expected the injected failure after the {$table} write.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("injected failure on {$table}", $e->getMessage());
            }
            DB::unprepared("DROP TRIGGER zz_inject ON {$table}");
            $this->assertSame($before, $this->counts(), "nothing survived a failure after the {$table} write");
            $this->assertSame('ACTIVE', $job->fresh()->state);
        }
        DB::unprepared('DROP FUNCTION zz_inject_failure()');

        $this->complete($job, 'clean');
        $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count(), 'a clean retry writes exactly one receipt after the failed attempts');
    }
}
