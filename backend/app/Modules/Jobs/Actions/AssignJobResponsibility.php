<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobAssignmentEvent;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Makes an active member of the company responsible for a RELEASED or ACTIVE Job, appending one assignment to the continuous chain (its predecessor is the previous
 * assignment) with an optional intended window and instructions, plus the ASSIGN event, the head advance and the actor's receipt — atomically. Refused while any hold is
 * unresolved (a hold blocks ordinary mutation). Responsibility grants the assignee nothing and starts nothing. Needs `job.assign`, which is SPECIFIED today, so every
 * caller is refused until it is activated. Internal: no route, receiver or Job creation.
 */
final class AssignJobResponsibility
{
    public function __construct(private readonly JobLifecycleOperation $operation) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation (baseline_version, assignment_sequence, open_holds, source_vector) */
    public function execute(
        User $actor,
        int $jobId,
        int $assigneeId,
        int $expectedGeneration,
        string $requestKey,
        string $commandIdentity,
        ?string $reason = null,
        ?Carbon $intendedFrom = null,
        ?Carbon $intendedUntil = null,
        ?string $instructions = null,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        if ($intendedUntil !== null && ($intendedFrom === null || $intendedUntil->lt($intendedFrom))) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'An intended end needs a start and cannot be before it.', 'intended_until');
        }
        $instructions = $instructions === null ? null : trim($instructions);
        if ($instructions !== null && ($instructions === '' || mb_strlen($instructions) > 2000)) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'Instructions are 1 to 2000 characters.', 'instructions');
        }
        $iso = static fn (?Carbon $time): ?string => $time?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $intent = new JobLifecycleIntent(JobLifecycleOperation::ASSIGN, $jobId, $commandIdentity, $reason, [
            'assignee_id' => $assigneeId, 'intended_from' => $iso($intendedFrom), 'intended_until' => $iso($intendedUntil), 'instructions' => $instructions,
        ], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);

        return $this->operation->run(
            $actor, ['job.assign'], $intent, $requestKey, null,
            function (JobSnapshot $snapshot, JobExecutionContext $context) use ($assigneeId): void {
                if (! in_array($snapshot->job->state, ['RELEASED', 'ACTIVE'], true)) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'Responsibility can only change on a released or active Job.', 'job_id');
                }
                if ($snapshot->openHoldIds !== []) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_HELD, 'The Job has an unresolved hold; resolve it before changing who is responsible.', 'job_id');
                }
                JobLifecycleOperation::eligibleAssignee($assigneeId);
            },
            function (JobEvent $event, JobSnapshot $snapshot) use ($assigneeId, $intendedFrom, $intendedUntil, $instructions, $actor): void {
                JobAssignmentEvent::query()->create([
                    'job_id' => $event->job_id, 'event_id' => $event->id, 'sequence' => $snapshot->assignmentSequence + 1, 'predecessor_id' => $snapshot->latestAssignmentId,
                    'responsible_user_id' => $assigneeId, 'intended_from' => $intendedFrom, 'intended_until' => $intendedUntil, 'instructions' => $instructions,
                    'actor_id' => $actor->id, 'occurred_at' => $event->occurred_at, 'occurrence_precision' => $event->occurrence_precision, 'reason' => $event->reason,
                ]);
            },
        );
    }
}
