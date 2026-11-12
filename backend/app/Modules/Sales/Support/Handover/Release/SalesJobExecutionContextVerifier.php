<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Sales\Models\HandoverReleaseEvent;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The source verifier for Jobs released from a Sales service handover, and ONLY for them. Entered inside the command's transaction BEFORE the Job row is locked, it takes
 * the Sales fences in the preparation order (authority head, acceptance, case, claim heads), re-proves the lineage and the no-obligation applicability, and returns the
 * source vector. A Job with no Sales origin, or a release that cannot be found, refuses: it is never a "no impact" success. It is NOT bound here; the Jobs module binds it.
 */
final class SalesJobExecutionContextVerifier implements JobExecutionContextVerifier
{
    public function __construct(private readonly JobSalesOrigin $origins, private readonly HandoverAccess $access, private readonly SourceApplicabilityProof $applicability) {}

    public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The source verifier must run inside the command transaction.');
        }
        $eventId = $this->origins->releaseEventIdFor($job)
            ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'This Job has no supported source integration.', 'job_id');
        $event = HandoverReleaseEvent::query()->find($eventId);
        $acceptanceId = $event === null ? null : ReleaseLineage::acceptanceIdOfStage($event->stage_id);
        if ($event === null || $acceptanceId === null) {
            return new JobExecutionContext([], false, false);
        }
        $subject = $this->access->lockSource($acceptanceId);
        $proof = $this->applicability->evaluate();
        $vector = [
            'release_event' => (string) $event->id,
            'condition' => $event->condition_identity,
            'applicability' => $proof['status'].':'.$proof['version'],
            'acceptance' => (string) $subject->acceptance->id,
        ];

        // Eligible: the accepted source still exists. Ready for fresh work only while the no-obligation proof still holds.
        return new JobExecutionContext($vector, true, $proof['status'] === 'NOT_APPLICABLE');
    }
}
