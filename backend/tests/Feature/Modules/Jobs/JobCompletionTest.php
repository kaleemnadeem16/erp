<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\ResolveJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use App\Support\JobSource\FamilyAssessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — internal Job completion, through the REAL Sales release, receiver and source verifier. A started Job is completed by an accountable attestation that records who, when,
 * the statement and the ORIGINAL agreed work; holds, state, authority, staleness and an unready source each refuse; a replay never completes twice; the database refuses
 * forged completions. Everything is disabled: no duty is ACTIVE in production and there is no route.
 */
class JobCompletionTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
    }

    private function complete(ServiceJob $job, string $key = 'c1', ?string $identity = null, string $statement = 'The whole service was delivered as agreed.', ?int $generation = null, ?User $actor = null): JobLifecycleOutcome
    {
        $out = app(CompleteJob::class)->execute($actor ?? $this->checker, $job->id, $generation ?? (int) $job->fresh()->generation, $key, $identity ?? JobLifecycleIntent::newCommandIdentity(), $statement);
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

    public function test_a_started_job_completes_with_who_when_statement_and_the_original_agreed_work(): void
    {
        $job = $this->startedJob();
        $out = $this->complete($job);

        $this->assertSame('COMPLETED', $out->state);
        $this->assertSame('COMPLETE', $out->operation);
        $this->assertFalse($out->replayed);
        $this->assertSame('COMPLETED', $job->fresh()->state);
        $completion = DB::table('job_completions')->where('job_id', $job->id)->first();
        $this->assertSame($this->checker->id, (int) $completion->completed_by);
        $this->assertSame('The whole service was delivered as agreed.', $completion->statement);
        $event = DB::table('job_events')->where('id', $completion->event_id)->first();
        $this->assertSame('COMPLETE', $event->operation);
        $this->assertSame('The whole service was delivered as agreed.', $event->reason);
        // It attests the ORIGINAL agreed work: the Job's baseline, its Sales release and the agreed-conditions identity.
        $baseline = DB::table('job_baselines')->where('job_id', $job->id)->orderByDesc('version')->first();
        $component = DB::table('job_baseline_components')->where('job_id', $job->id)->first();
        $origin = DB::table('job_sales_origins')->where('job_id', $job->id)->first();
        $this->assertSame((int) $baseline->id, (int) $completion->baseline_id);
        $this->assertSame($baseline->fingerprint, $completion->baseline_fingerprint);
        $this->assertSame((int) $origin->release_event_id, (int) $completion->release_event_id);
        $this->assertSame($component->condition_identity, $completion->condition_identity);
        $this->assertSame(1, DB::table('job_operation_receipts')->where('result_event_id', $completion->event_id)->count());
    }

    public function test_the_same_key_a_fresh_key_and_a_second_actor_complete_it_only_once(): void
    {
        $job = $this->startedJob();
        $identity = JobLifecycleIntent::newCommandIdentity();
        $first = $this->complete($job, 'c1', $identity, generation: (int) $job->fresh()->generation);
        $generation = $first->generation;
        $again = app(CompleteJob::class)->execute($this->checker, $job->id, $generation - 1, 'c1', $identity, 'The whole service was delivered as agreed.');
        $this->assertTrue($again->replayed);
        $this->assertSame($first->eventId, $again->eventId);
        // The same business command through a fresh key, and through another actor who may complete, resolves to the same event.
        $fresh = app(CompleteJob::class)->execute($this->checker, $job->id, $generation - 1, 'c2', $identity, 'The whole service was delivered as agreed.');
        $this->assertSame($first->eventId, $fresh->eventId);
        $second = User::factory()->create();
        $this->grantJobDuties($second, 'job.complete');
        $other = app(CompleteJob::class)->execute($second, $job->id, $generation - 1, 'c3', $identity, 'The whole service was delivered as agreed.');
        $this->assertSame($first->eventId, $other->eventId);
        $this->settleConstraints();
        $this->assertSame(1, DB::table('job_completions')->where('job_id', $job->id)->count());
        $this->assertSame(1, DB::table('job_events')->where('job_id', $job->id)->where('operation', 'COMPLETE')->count());
        // A genuinely new command on a completed Job is refused: it cannot complete twice.
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => app(CompleteJob::class)->execute($this->checker, $job->id, $generation, 'c4', JobLifecycleIntent::newCommandIdentity(), 'Again')));
        // A used key with a different statement conflicts.
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(CompleteJob::class)->execute($this->checker, $job->id, $generation - 1, 'c1', $identity, 'A different statement')));
    }

    public function test_only_a_started_job_with_no_open_hold_and_a_statement_can_be_completed(): void
    {
        $out = $this->releaseTheService();
        $released = ServiceJob::query()->findOrFail($out['job_id']);
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => $this->complete($released)), 'a released (not started) Job cannot be completed');

        $job = $this->startedJob2($released);
        $hold = app(PlaceJobHold::class)->execute($this->checker, $job->id, 'Waiting for the customer', (int) $job->fresh()->generation, 'hold-1', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();
        $this->assertSame('JOB_HELD', $this->refusal(fn () => $this->complete($job)), 'an open hold blocks completion');
        $holdId = (int) DB::table('job_holds')->where('job_id', $job->id)->value('id');
        app(ResolveJobHold::class)->execute($this->checker, $job->id, $holdId, 'Customer replied', (int) $job->fresh()->generation, 'resolve-1', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->complete($job, 'blank', statement: '   ')), 'a completion statement is required');
        $this->assertSame('COMPLETED', $this->complete($job, 'ok')->state);
        $this->assertNotNull($hold);
    }

    /** Takes an already released Job to ACTIVE. */
    private function startedJob2(ServiceJob $released): ServiceJob
    {
        $assigned = app(AssignJobResponsibility::class)->execute($this->checker, $released->id, $this->checker->id, 1, 'assign-x', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();
        app(StartJob::class)->execute($this->checker, $released->id, $assigned->generation, 'start-x', JobLifecycleIntent::newCommandIdentity(), 'Go');
        $this->settleConstraints();

        return $released->refresh();
    }

    public function test_job_complete_is_required_independently_and_revocation_applies_before_a_replay(): void
    {
        $job = $this->startedJob();
        $nobody = User::factory()->create();
        try {
            app(CompleteJob::class)->execute($nobody, $job->id, (int) $job->fresh()->generation, 'n1', JobLifecycleIntent::newCommandIdentity(), 'x');
            $this->fail('No duty must refuse.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        // job.close, job.start and the rest do not stand in for job.complete.
        $other = User::factory()->create();
        $this->grantJobDuties($other, 'job.close', 'job.start', 'job.assign', 'job.hold');
        try {
            app(CompleteJob::class)->execute($other, $job->id, (int) $job->fresh()->generation, 'n2', JobLifecycleIntent::newCommandIdentity(), 'x');
            $this->fail('Another duty must not stand in.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $identity = JobLifecycleIntent::newCommandIdentity();
        $out = $this->complete($job, 'c1', $identity);
        $this->checker->revokePermissionTo('job.complete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            app(CompleteJob::class)->execute($this->checker->fresh(), $job->id, $out->generation - 1, 'c1', $identity, 'The whole service was delivered as agreed.');
            $this->fail('A revoked duty must refuse even a replay.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_stale_generation_and_a_stale_source_vector_are_refused(): void
    {
        $job = $this->startedJob();
        $this->assertSame('STALE_GENERATION', $this->refusal(fn () => $this->complete($job, 's1', generation: (int) $job->fresh()->generation - 1)));
        $this->assertSame('STALE_SOURCE_CONTEXT', $this->refusal(fn () => app(CompleteJob::class)->execute(
            $this->checker, $job->id, (int) $job->fresh()->generation, 's2', JobLifecycleIntent::newCommandIdentity(), 'x', expected: ['source_vector' => ['release_event' => '0']],
        )));
        $this->assertSame(0, DB::table('job_completions')->count());
    }

    public function test_an_unready_source_refuses_completion_and_nothing_is_written(): void
    {
        $job = $this->startedJob();
        // A family authority that can no longer prove "no obligation" makes the source unready at COMPLETE, as at START.
        $this->registerSourceFamilies(FamilyAssessment::UNKNOWN);
        $counts = [DB::table('job_events')->count(), DB::table('job_completions')->count(), DB::table('job_operation_receipts')->count()];
        $this->assertSame('NOT_READY_TO_COMPLETE', $this->refusal(fn () => $this->complete($job, 'u1')));
        $this->assertSame($counts, [DB::table('job_events')->count(), DB::table('job_completions')->count(), DB::table('job_operation_receipts')->count()]);
        $this->assertSame('ACTIVE', $job->fresh()->state);
    }

    public function test_an_unsupported_origin_fails_closed_and_another_company_sees_nothing(): void
    {
        $job = $this->startedJob();
        $raw = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'ACTIVE', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $raw, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'ACTIVE', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => app(CompleteJob::class)->execute($this->checker, $raw, 1, 'raw', JobLifecycleIntent::newCommandIdentity(), 'x')));
        $this->assertSame('JOB_NOT_FOUND', $this->refusal(fn () => app(CompleteJob::class)->execute($this->checker, 9999999, 1, 'none', JobLifecycleIntent::newCommandIdentity(), 'x')));
        $this->assertNotNull($job);
    }

    /** The database must refuse the write, and for the stated reason (a different, unrelated error would not prove the guard). */
    private function refusedBecause(string $fragment, callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail("The database must refuse this write ({$fragment}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** A raw COMPLETE of a started Job with its receipt and head advance, and a completion row built from the Job's real facts unless overridden (null = no completion row). */
    private function rawComplete(ServiceJob $job, ?array $override): void
    {
        $head = DB::table('service_jobs')->where('id', $job->id)->first();
        $sequence = (int) DB::table('job_events')->where('job_id', $job->id)->max('sequence') + 1;
        $event = (int) DB::table('job_events')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'sequence' => $sequence, 'operation' => 'COMPLETE', 'from_state' => 'ACTIVE', 'to_state' => 'COMPLETED',
            'prior_generation' => $head->generation, 'next_generation' => $head->generation + 1, 'occurrence_precision' => 'NONE', 'recorded_at' => now(), 'actor_id' => $this->checker->id,
            'evidence_version' => 1, 'reason' => 'Raw', 'command_identity' => JobLifecycleIntent::newCommandIdentity(), 'semantic_intent_hash' => str_repeat('a', 64)]);
        DB::table('job_operation_receipts')->insert(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'raw-'.$event, 'operation' => 'COMPLETE',
            'intent_hash' => str_repeat('a', 64), 'result_event_id' => $event, 'contract_version' => 1, 'recorded_at' => now()]);
        DB::table('service_jobs')->where('id', $job->id)->update(['state' => 'COMPLETED', 'generation' => $head->generation + 1]);
        if ($override !== null) {
            $baseline = DB::table('job_baselines')->where('job_id', $job->id)->orderByDesc('version')->first();
            $component = DB::table('job_baseline_components')->where('job_id', $job->id)->first();
            $origin = DB::table('job_sales_origins')->where('job_id', $job->id)->first();
            DB::table('job_completions')->insert($override + ['company_id' => 1, 'job_id' => $job->id, 'event_id' => $event, 'baseline_id' => $baseline->id, 'release_event_id' => $origin->release_event_id,
                'condition_identity' => $component->condition_identity, 'baseline_fingerprint' => $baseline->fingerprint, 'statement' => 'Raw', 'completed_by' => $this->checker->id,
                'occurrence_precision' => 'NONE']);
            // A Sales-origin completion also carries its Sales fulfilment receipt (the database refuses a COMPLETE event without it); the completion rows above are what the cases forge.
            $issued = DB::table('quotation_issued_lines')->where('id', $component->issued_line_id)->first();
            DB::table('handover_fulfilment_receipts')->insert(['company_id' => 1, 'job_id' => $job->id, 'event_id' => $event, 'release_event_id' => $origin->release_event_id, 'issued_line_id' => $component->issued_line_id,
                'basis_id' => $component->basis_id, 'condition_identity' => $component->condition_identity, 'deliverable_digest' => ScopeHeader::digest((string) $issued->description), 'completed_by' => $this->checker->id]);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_the_database_refuses_forged_or_cross_company_completions_and_history_is_immutable(): void
    {
        $job = $this->startedJob();
        // A well-formed raw completion is accepted (so the refusals below are for their stated reasons, not for a malformed fixture).
        DB::transaction(fn () => $this->rawComplete($job, []));
        $this->assertSame(1, DB::table('job_completions')->where('job_id', $job->id)->count());
        $completion = (array) DB::table('job_completions')->where('job_id', $job->id)->first();
        unset($completion['id']);

        $this->refusedBecause('job_completions_job_unique', fn () => DB::table('job_completions')->insert($completion));
        $this->refusedBecause('append-only', fn () => DB::table('job_completions')->where('job_id', $job->id)->update(['statement' => 'changed']));
        $this->refusedBecause('append-only', fn () => DB::table('job_completions')->where('job_id', $job->id)->delete());
        // The Sales fulfilment receipts reference job_completions, so the database refuses the TRUNCATE on that ground before the append-only trigger is reached.
        $this->refusedBecause('cannot truncate a table referenced in a foreign key', fn () => DB::statement('TRUNCATE job_completions'));
        $this->refusedBecause('foreign key', fn () => DB::table('job_completions')->insert(['company_id' => 999] + $completion));
    }

    public function test_a_complete_event_never_commits_without_its_completion_or_with_a_completion_that_misstates_the_agreed_work(): void
    {
        $job = $this->startedJob();
        $this->refusedBecause('does not have exactly its own completion or closeout evidence', fn () => $this->rawComplete($job, null));
        $this->refusedBecause('must attest the original agreed work', fn () => $this->rawComplete($job, ['baseline_fingerprint' => str_repeat('f', 64)]));
        $this->refusedBecause('must attest the original agreed work', fn () => $this->rawComplete($job, ['condition_identity' => 'forged-conditions']));
        $this->refusedBecause('does not agree with its COMPLETE event', fn () => $this->rawComplete($job, ['statement' => 'A different statement']));
        $this->refusedBecause('does not agree with its COMPLETE event', fn () => $this->rawComplete($job, ['completed_by' => $this->worker->id]));
        // Nothing of the refused attempts survived.
        $this->assertSame('ACTIVE', DB::table('service_jobs')->where('id', $job->id)->value('state'));
        $this->assertSame(0, DB::table('job_completions')->count());
    }

    public function test_a_complete_event_over_an_unresolved_hold_is_refused_by_the_database(): void
    {
        $job = $this->startedJob();
        app(PlaceJobHold::class)->execute($this->checker, $job->id, 'Waiting', (int) $job->fresh()->generation, 'hold-raw', JobLifecycleIntent::newCommandIdentity());
        $this->settleConstraints();
        $this->refusedBecause('unresolved hold', fn () => $this->rawComplete($job, []));
    }

    public function test_completion_does_not_touch_the_phase_four_claims(): void
    {
        $job = $this->startedJob();
        $claims = DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all();
        $acceptances = DB::table('customer_acceptances')->count();
        $this->complete($job);
        $this->assertEquals($claims, DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all());
        $this->assertSame($acceptances, DB::table('customer_acceptances')->count());
    }
}
