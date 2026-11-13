<?php

namespace Tests\Feature\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Support\JobSource\FamilyAssessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — internal Job closeout: a separate immutable assessment, then a close on a READY assessment of exactly the generation closed. Unresolved holds, an unready source, an
 * unsupported origin and a missing completion each block; a replay never creates a second closeout; the database refuses forged closeouts. Closing settles no invoice or balance.
 */
class JobCloseoutTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStartedJobFixture();
    }

    private function completedJob(): ServiceJob
    {
        $job = $this->startedJob();
        app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'complete-1', JobLifecycleIntent::newCommandIdentity(), 'Delivered in full.');
        $this->settleConstraints();

        return $job->refresh();
    }

    private function assess(ServiceJob $job, string $key = 'a1', ?int $generation = null, ?User $actor = null): JobCloseoutOutcome
    {
        $out = app(AssessJobCloseout::class)->execute($actor ?? $this->checker, $job->id, $generation ?? (int) $job->fresh()->generation, $key);
        $this->settleConstraints();

        return $out;
    }

    private function close(ServiceJob $job, int $assessmentId, string $key = 'k1', ?string $identity = null, ?int $generation = null, ?User $actor = null): JobLifecycleOutcome
    {
        $out = app(CloseJob::class)->execute($actor ?? $this->checker, $job->id, $generation ?? (int) $job->fresh()->generation, $assessmentId, $key, $identity ?? JobLifecycleIntent::newCommandIdentity(), 'Closed.');
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

    public function test_a_completed_job_is_assessed_ready_then_closed_with_a_permanent_event_receipt_and_closeout(): void
    {
        $job = $this->completedJob();
        $claims = DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all();
        $generation = (int) $job->fresh()->generation;
        $assessment = $this->assess($job);
        $this->assertSame('READY', $assessment->status);
        $this->assertSame([], $assessment->blockers);
        $this->assertSame($generation, $assessment->generation);
        $this->assertSame('COMPLETED', $job->fresh()->state, 'assessing never changes the Job');
        $this->assertSame($generation, (int) $job->fresh()->generation);

        $out = $this->close($job, $assessment->assessmentId);
        $this->assertSame('CLOSED', $out->state);
        $this->assertSame('CLOSE', $out->operation);
        $this->assertSame('CLOSED', $job->fresh()->state);
        $closeout = DB::table('job_closeouts')->where('job_id', $job->id)->first();
        $this->assertSame($assessment->assessmentId, (int) $closeout->assessment_id);
        $this->assertSame($this->checker->id, (int) $closeout->closed_by);
        $this->assertSame('Closed.', $closeout->statement);
        $this->assertSame(1, DB::table('job_operation_receipts')->where('result_event_id', $closeout->event_id)->count());
        $this->assertSame('CLOSE', DB::table('job_events')->where('id', $closeout->event_id)->value('operation'));
        // Closing is operational only: Phase 4 claims, acceptances and everything financial are untouched.
        $this->assertEquals($claims, DB::table('customer_scope_claim_heads')->orderBy('id')->get()->all());
        $this->assertSame(1, DB::table('customer_acceptances')->count());
    }

    public function test_a_job_that_is_not_completed_is_blocked_and_cannot_be_closed(): void
    {
        $job = $this->startedJob();
        $assessment = $this->assess($job);
        $this->assertSame('BLOCKED', $assessment->status);
        $this->assertContains('JOB_NOT_COMPLETED', $assessment->blockers);
        $this->assertContains('COMPLETION_MISSING', $assessment->blockers);
        $this->assertSame('ACTIVE', $job->fresh()->state);
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => $this->close($job, $assessment->assessmentId)));
        $this->assertSame(0, DB::table('job_closeouts')->count());
    }

    public function test_a_blocked_or_unknown_assessment_cannot_be_used_and_a_source_change_blocks_the_close(): void
    {
        $job = $this->completedJob();
        // The source can no longer prove "no obligation": the assessment is BLOCKED and can never close the Job.
        $this->registerSourceFamilies(FamilyAssessment::UNKNOWN);
        $blocked = $this->assess($job, 'b1');
        $this->assertSame('BLOCKED', $blocked->status);
        $this->assertSame(['SOURCE_NOT_READY'], $blocked->blockers);
        $this->registerSourceFamilies();
        $this->assertSame('CLOSEOUT_ASSESSMENT_REQUIRED', $this->refusal(fn () => $this->close($job, $blocked->assessmentId, 'k-blocked')));
        $this->assertSame('CLOSEOUT_ASSESSMENT_REQUIRED', $this->refusal(fn () => $this->close($job, 9999999, 'k-none')));

        // A READY assessment is never trusted past the moment it was made: the close re-runs the blockers under the Job lock.
        $ready = $this->assess($job, 'r1');
        $this->assertSame('READY', $ready->status);
        $this->registerSourceFamilies(FamilyAssessment::UNKNOWN);
        $this->assertSame('CLOSEOUT_BLOCKED', $this->refusal(fn () => $this->close($job, $ready->assessmentId, 'k-late')));
        $this->assertSame('COMPLETED', $job->fresh()->state);
        $this->assertSame(0, DB::table('job_closeouts')->count());
    }

    public function test_replays_create_no_second_assessment_or_closeout(): void
    {
        $job = $this->completedJob();
        $first = $this->assess($job, 'a1');
        $again = app(AssessJobCloseout::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'a1');
        $this->assertTrue($again->replayed);
        $this->assertSame($first->assessmentId, $again->assessmentId);
        $this->assertSame(1, DB::table('job_closeout_assessments')->count());
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(AssessJobCloseout::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation + 1, 'a1')));

        $identity = JobLifecycleIntent::newCommandIdentity();
        $closed = $this->close($job, $first->assessmentId, 'k1', $identity);
        $generation = $closed->generation;
        $same = app(CloseJob::class)->execute($this->checker, $job->id, $generation - 1, $first->assessmentId, 'k1', $identity, 'Closed.');
        $this->assertTrue($same->replayed);
        $this->assertSame($closed->eventId, $same->eventId);
        $fresh = app(CloseJob::class)->execute($this->checker, $job->id, $generation - 1, $first->assessmentId, 'k2', $identity, 'Closed.');
        $this->assertSame($closed->eventId, $fresh->eventId);
        $second = User::factory()->create();
        $this->grantJobDuties($second, 'job.close');
        $other = app(CloseJob::class)->execute($second, $job->id, $generation - 1, $first->assessmentId, 'k3', $identity, 'Closed.');
        $this->assertSame($closed->eventId, $other->eventId);
        $this->settleConstraints();
        $this->assertSame(1, DB::table('job_closeouts')->count());
        $this->assertSame(1, DB::table('job_events')->where('operation', 'CLOSE')->count());
        // A new command on a closed Job is refused.
        $this->assertSame('JOB_STATE_NOT_ALLOWED', $this->refusal(fn () => app(CloseJob::class)->execute($this->checker, $job->id, $generation, $first->assessmentId, 'k4', JobLifecycleIntent::newCommandIdentity())));
    }

    public function test_job_close_is_required_independently_and_revocation_applies_before_a_replay(): void
    {
        $job = $this->completedJob();
        $someone = User::factory()->create();
        $this->grantJobDuties($someone, 'job.complete', 'job.start', 'job.assign');
        foreach ([fn () => $this->assess($job, 'x1', actor: $someone), fn () => $this->close($job, 1, 'x2', actor: $someone)] as $attempt) {
            try {
                $attempt();
                $this->fail('job.complete must not stand in for job.close.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $assessment = $this->assess($job, 'a1');
        $this->checker->revokePermissionTo('job.close');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            app(AssessJobCloseout::class)->execute($this->checker->fresh(), $job->id, (int) $job->fresh()->generation, 'a1');
            $this->fail('A revoked duty must refuse even a replay.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull($assessment);
    }

    public function test_stale_generation_unsupported_origin_and_unknown_job_are_refused(): void
    {
        $job = $this->completedJob();
        $this->assertSame('STALE_GENERATION', $this->refusal(fn () => $this->assess($job, 's1', (int) $job->fresh()->generation - 1)));
        $ready = $this->assess($job, 's2');
        $this->assertSame('STALE_GENERATION', $this->refusal(fn () => $this->close($job, $ready->assessmentId, 's3', generation: (int) $job->fresh()->generation - 1)));
        $this->assertSame('JOB_NOT_FOUND', $this->refusal(fn () => app(AssessJobCloseout::class)->execute($this->checker, 9999999, 1, 'none')));
        $raw = (int) DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'JOB-RAW', 'state' => 'COMPLETED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $raw, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'COMPLETED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1]);
        $this->assertSame('SOURCE_CONTEXT_UNAVAILABLE', $this->refusal(fn () => app(AssessJobCloseout::class)->execute($this->checker, $raw, 1, 'raw')));
    }

    public function test_the_database_refuses_forged_closeouts_and_history_is_immutable(): void
    {
        $job = $this->completedJob();
        $ready = $this->assess($job, 'a1');
        $blocked = (int) DB::table('job_closeout_assessments')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'forged', 'intent_hash' => str_repeat('b', 64),
            'at_generation' => (int) $job->fresh()->generation, 'status' => 'BLOCKED', 'blockers' => json_encode(['OPEN_HOLDS']), 'recorded_at' => now()]);
        $this->close($job, $ready->assessmentId);
        $closeout = (array) DB::table('job_closeouts')->where('job_id', $job->id)->first();
        unset($closeout['id']);
        $refused = function (string $fragment, callable $work): void {
            try {
                DB::transaction($work);
                $this->fail("The database must refuse this write ({$fragment}).");
            } catch (QueryException $e) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        };
        $refused('job_closeouts_job_unique', fn () => DB::table('job_closeouts')->insert($closeout));
        $refused('append-only', fn () => DB::table('job_closeouts')->where('job_id', $job->id)->update(['statement' => 'changed']));
        $refused('append-only', fn () => DB::table('job_closeouts')->where('job_id', $job->id)->delete());
        $refused('append-only', fn () => DB::statement('TRUNCATE job_closeouts'));
        $refused('append-only', fn () => DB::table('job_closeout_assessments')->where('id', $ready->assessmentId)->update(['status' => 'BLOCKED']));
        $refused('append-only', fn () => DB::statement('TRUNCATE job_closeout_assessments CASCADE'));
        $refused('foreign key', fn () => DB::table('job_closeouts')->insert(['company_id' => 999] + $closeout));
        // The shape rule: READY has no blockers, BLOCKED has at least one.
        $refused('job_closeout_assessments_shape', fn () => DB::table('job_closeout_assessments')->insert(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'bad', 'intent_hash' => str_repeat('c', 64),
            'at_generation' => 1, 'status' => 'READY', 'blockers' => json_encode(['OPEN_HOLDS']), 'recorded_at' => now()]));
        $this->assertGreaterThan(0, $blocked);
    }

    public function test_the_database_refuses_a_close_on_a_blocked_or_stale_assessment_and_a_close_without_its_closeout(): void
    {
        $job = $this->completedJob();
        $generation = (int) $job->fresh()->generation;
        $blocked = (int) DB::table('job_closeout_assessments')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'forged', 'intent_hash' => str_repeat('b', 64),
            'at_generation' => $generation, 'status' => 'BLOCKED', 'blockers' => json_encode(['OPEN_HOLDS']), 'recorded_at' => now()]);
        $stale = (int) DB::table('job_closeout_assessments')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'stale', 'intent_hash' => str_repeat('d', 64),
            'at_generation' => $generation - 1, 'status' => 'READY', 'blockers' => json_encode([]), 'recorded_at' => now()]);
        $rawClose = function (?int $assessment) use ($job, $generation): void {
            $identity = JobLifecycleIntent::newCommandIdentity();
            $latest = (int) DB::table('job_events')->where('job_id', $job->id)->max('sequence');
            $event = (int) DB::table('job_events')->insertGetId(['company_id' => 1, 'job_id' => $job->id, 'sequence' => $latest + 1, 'operation' => 'CLOSE', 'from_state' => 'COMPLETED', 'to_state' => 'CLOSED',
                'prior_generation' => $generation, 'next_generation' => $generation + 1, 'occurrence_precision' => 'NONE', 'recorded_at' => now(), 'actor_id' => $this->checker->id, 'evidence_version' => 1,
                'command_identity' => $identity, 'semantic_intent_hash' => str_repeat('a', 64)]);
            DB::table('job_operation_receipts')->insert(['company_id' => 1, 'job_id' => $job->id, 'actor_id' => $this->checker->id, 'request_key' => 'raw-'.$event, 'operation' => 'CLOSE',
                'intent_hash' => str_repeat('a', 64), 'result_event_id' => $event, 'contract_version' => 1, 'recorded_at' => now()]);
            DB::table('service_jobs')->where('id', $job->id)->update(['state' => 'CLOSED', 'generation' => $generation + 1]);
            if ($assessment !== null) {
                DB::table('job_closeouts')->insert(['company_id' => 1, 'job_id' => $job->id, 'event_id' => $event, 'assessment_id' => $assessment, 'closed_by' => $this->checker->id, 'occurrence_precision' => 'NONE']);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        };
        foreach ([[$blocked, 'READY assessment of the generation being closed'], [$stale, 'READY assessment of the generation being closed'], [null, 'does not have exactly its own completion or closeout evidence']] as [$assessment, $fragment]) {
            try {
                DB::transaction(fn () => $rawClose($assessment));
                $this->fail('The database must refuse this close.');
            } catch (QueryException $e) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }
        $this->assertSame('COMPLETED', DB::table('service_jobs')->where('id', $job->id)->value('state'));
    }

    public function test_a_hold_after_completion_is_refused(): void
    {
        $job = $this->completedJob();
        try {
            app(PlaceJobHold::class)->execute($this->checker, $job->id, 'Too late', (int) $job->fresh()->generation, 'h1', JobLifecycleIntent::newCommandIdentity());
            $this->settleConstraints();
            $this->fail('A completed Job cannot be held.');
        } catch (DomainInvariantException|QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
