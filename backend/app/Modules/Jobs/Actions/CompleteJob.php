<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobBaseline;
use App\Modules\Jobs\Models\JobBaselineComponent;
use App\Modules\Jobs\Models\JobCompletion;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobSalesOrigin;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use App\Modules\Sales\Support\Handover\Release\FulfilmentFact;
use App\Modules\Sales\Support\Handover\Release\ServiceFulfilmentRecorder;
use Illuminate\Support\Carbon;

/**
 * Completes a started Job: an accountable attestation that the WHOLE agreed deliverable was done. A started (ACTIVE) Job with no unresolved hold, a current verified source
 * context (the source re-proves its no-obligation applicability and pinned conditions; anything unsupported or unprovable refuses) and a completion statement are required.
 * It appends the COMPLETE event (ACTIVE to COMPLETED), the immutable completion row — who, when, the statement and the ORIGINAL customer-agreed work it attests (the Job's baseline,
 * its Sales release and the agreed-conditions identity and fingerprint) — the head advance and the actor's receipt, atomically. A replay returns the original; there is no second
 * completion; the Sales source's immutable fulfilment receipt (the exact agreed deliverable and conditions were fulfilled) is written in the SAME transaction, so both exist or neither does.
 * No timesheet, labour, cost or signature is required or recorded. Needs `job.complete`, SPECIFIED today, so every caller is refused until it is activated.
 */
final class CompleteJob
{
    public function __construct(private readonly JobLifecycleOperation $operation, private readonly ServiceFulfilmentRecorder $fulfilment) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation */
    public function execute(
        User $actor,
        int $jobId,
        int $expectedGeneration,
        string $requestKey,
        string $commandIdentity,
        string $statement,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        $statement = trim($statement);
        if ($statement === '' || mb_strlen($statement) > 1000) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'A completion statement of 1 to 1000 characters is required.', 'statement');
        }
        $intent = new JobLifecycleIntent(JobLifecycleOperation::COMPLETE, $jobId, $commandIdentity, $statement, [], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);

        $pin = null;

        return $this->operation->run(
            $actor, ['job.complete'], $intent, $requestKey, 'COMPLETED',
            function (JobSnapshot $snapshot, JobExecutionContext $context) use (&$pin): void {
                $pin = is_string($context->sourceVector['applicability_pin'] ?? null) ? $context->sourceVector['applicability_pin'] : null;
                if ($snapshot->job->state !== 'ACTIVE') {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'Only a started Job can be completed.', 'job_id');
                }
                if ($snapshot->openHoldIds !== []) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_HELD, 'The Job has an unresolved hold; resolve it before completing.', 'job_id');
                }
                if (! $context->commandReady) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::NOT_READY_TO_COMPLETE, 'The Job is not ready to be completed.', 'job_id');
                }
            },
            function (JobEvent $event, JobSnapshot $snapshot) use ($statement, $actor, $occurredAt, $occurrencePrecision, &$pin): void {
                $baseline = JobBaseline::query()->where('job_id', $snapshot->job->id)->orderByDesc('version')->firstOrFail();
                $component = JobBaselineComponent::query()->where(['job_id' => $snapshot->job->id, 'baseline_id' => $baseline->id])->firstOrFail();
                $origin = JobSalesOrigin::query()->where('job_id', $snapshot->job->id)->firstOrFail();
                JobCompletion::query()->create([
                    'job_id' => $snapshot->job->id, 'event_id' => $event->id, 'baseline_id' => $baseline->id, 'release_event_id' => $origin->release_event_id,
                    'condition_identity' => $component->condition_identity, 'baseline_fingerprint' => $baseline->fingerprint, 'statement' => $statement, 'completed_by' => $actor->id,
                    'occurred_at' => $occurredAt, 'occurrence_precision' => $occurrencePrecision,
                ]);
                // The Sales fulfilment receipt, in this same transaction and after the source fences and the Job head lock: a failure here rolls the whole completion back.
                $this->fulfilment->record(new FulfilmentFact($snapshot->job->id, $event->id, $actor->id, $pin));
            },
        );
    }
}
