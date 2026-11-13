<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobCloseoutAssessment;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutBlockers;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Assesses whether a Job can be closed and records the answer as immutable evidence — READY, or BLOCKED with stable blocker codes — without changing the Job. Same order as every
 * lifecycle command: authority (`job.close`, SPECIFIED today, so every caller is refused until activated) → the Job (one non-disclosing refusal for absent or foreign) → inside
 * the transaction the source verifier is entered FIRST (source fences before the Job lock) → a replay of the actor's own key → the Job row is locked, the replay is looked up again,
 * the declared expected generation is checked and the blockers are decided from the locked head. A READY assessment is valid for exactly the generation it was made at.
 */
final class AssessJobCloseout
{
    public function __construct(
        private readonly JobExecutionContextVerifier $verifier,
        private readonly JobLifecycleOperation $operation,
        private readonly JobCloseoutBlockers $blockers,
    ) {}

    public function execute(User $actor, int $jobId, int $expectedGeneration, string $requestKey): JobCloseoutOutcome
    {
        if (! JobAccess::mayAll($actor, 'job.close')) {
            throw new AuthorizationException('You are not permitted to assess this Job.');
        }
        $requestKey = trim($requestKey);
        if ($requestKey === '' || mb_strlen($requestKey) > 120) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::REQUEST_KEY_REQUIRED, 'A request key of at most 120 characters is required so that retrying cannot repeat the assessment.', 'request_key');
        }
        if ($expectedGeneration < 1) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'The expected Job generation is required.', 'expected_generation');
        }
        $job = ServiceJob::query()->find($jobId) ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');
        $hash = hash('sha256', (string) json_encode([JobLifecycleOperation::ASSESS_CLOSEOUT, 1, $jobId, $expectedGeneration, $actor->id, $requestKey], JSON_THROW_ON_ERROR));

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): JobCloseoutOutcome => $this->attempt($actor, $job, $expectedGeneration, $requestKey, $hash));
            } catch (UniqueConstraintViolationException) {
                // Lost a race on this actor's key: restart the whole operation (the replay now finds the winner). Never continue in an aborted transaction.
                if (DB::transactionLevel() > 0 || $attempt >= 3) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::CONCURRENT_CHANGE, 'The Job changed at the same time; retry the request.', 'job_id');
                }
            }
        }
    }

    private function attempt(User $actor, ServiceJob $job, int $expectedGeneration, string $requestKey, string $hash): JobCloseoutOutcome
    {
        // Authority is judged again from the database at the start of every attempt, before any replay is read.
        if (! JobAccess::mayAll($actor, 'job.close')) {
            throw new AuthorizationException('You are not permitted to assess this Job.');
        }
        $context = $this->verifier->enter($actor, $job, JobLifecycleOperation::ASSESS_CLOSEOUT);
        // The fences may have made this attempt wait: authority is judged again under them, before any replay is returned or anything is written.
        if (! JobAccess::mayAll($actor, 'job.close')) {
            throw new AuthorizationException('You are not permitted to assess this Job.');
        }
        if (! $context->recordEligible) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');
        }
        if (($replay = $this->replay($actor, $job, $requestKey, $hash)) !== null) {
            return $replay;
        }
        $locked = ServiceJob::query()->whereKey($job->id)->lockForUpdate()->first() ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');
        if (($replay = $this->replay($actor, $job, $requestKey, $hash)) !== null) {
            return $replay;
        }
        $snapshot = $this->operation->snapshot($locked);
        if ($locked->generation !== $expectedGeneration) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::STALE_GENERATION, "The Job is at generation {$locked->generation}, not {$expectedGeneration}; reload it before assessing it.", 'expected_generation');
        }
        $blockers = $this->blockers->for($snapshot, $context);
        $assessment = JobCloseoutAssessment::query()->create([
            'job_id' => $locked->id, 'actor_id' => $actor->id, 'request_key' => $requestKey, 'intent_hash' => $hash, 'at_generation' => $locked->generation,
            'status' => $blockers === [] ? 'READY' : 'BLOCKED', 'blockers' => $blockers, 'source_pin' => $this->blockers->pin($context),
        ])->refresh();

        return $this->outcome($assessment, false);
    }

    /** @phpstan-impure */
    private function replay(User $actor, ServiceJob $job, string $requestKey, string $hash): ?JobCloseoutOutcome
    {
        $prior = JobCloseoutAssessment::query()->where(['actor_id' => $actor->id, 'request_key' => $requestKey])->first();
        if ($prior === null) {
            return null;
        }
        if ($prior->intent_hash !== $hash || $prior->job_id !== $job->id) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::REQUEST_KEY_REUSED, 'That request key was already used for a different request.', 'request_key');
        }

        return $this->outcome($prior, true);
    }

    private function outcome(JobCloseoutAssessment $assessment, bool $replayed): JobCloseoutOutcome
    {
        return new JobCloseoutOutcome($assessment->job_id, $assessment->id, $assessment->status, $assessment->blockers, $assessment->at_generation, $replayed);
    }
}
