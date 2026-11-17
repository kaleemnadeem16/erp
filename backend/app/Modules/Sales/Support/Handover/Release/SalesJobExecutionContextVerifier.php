<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Sales\Models\HandoverReleaseEvent;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The source verifier for Jobs released from a Sales service handover, and ONLY for them. Entered inside the command's transaction BEFORE the Job row is locked, it proves
 * CURRENT, ACTOR-SPECIFIC eligibility of the owning source record for the one operation asked, then takes the Sales fences in the preparation order (authority head, acceptance,
 * case, claim heads), re-proves the lineage and the no-obligation applicability, and returns the source vector. It is NOT bound here; the Jobs module binds it.
 *
 * Eligibility is exactly: (1) a supported, known operation; (2) a Job of THIS company whose Sales origin is the release event that names it (an unsupported origin refuses, it is
 * never a "no impact" success); (3) the company access fence first, then the actor read afresh as a currently active member of this company; (4) the accepted source rows locked
 * in the preparation order; (5) AFTER any fence wait, the actor's exact Jobs duty for the operation (each by its own ALL scope) judged again from the database; (6) the Job's
 * baseline component still names this release event and the locked accepted line. A Jobs-only operator therefore needs NO Sales read duty: the verifier's service-level read of
 * the owning source is distinct from a person's broad Sales visibility, and nothing here reveals a private source field. No new permission is introduced.
 *
 * A held authority failure is an AuthorizationException; anything unproved about the source (another company's Job, a missing event, a broken relationship) is not eligible,
 * which the caller answers with its one non-disclosing "not found".
 */
final class SalesJobExecutionContextVerifier implements JobExecutionContextVerifier
{
    public function __construct(private readonly JobSalesOrigin $origins, private readonly HandoverAccess $access, private readonly SourceApplicabilityProof $applicability, private readonly SalesCompletionBasis $bases) {}

    public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The source verifier must run inside the command transaction.');
        }
        $duties = JobLifecycleOperation::DUTIES[$operation] ?? null;
        if ($duties === null) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'This operation has no supported source integration.', 'job_id');
        }
        $company = app(CompanyContext::class)->id();
        if (! $job->exists || (int) $job->company_id !== $company) {
            return new JobExecutionContext([], false, false);
        }
        $eventId = $this->origins->releaseEventIdFor($job)
            ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'This Job has no supported source integration.', 'job_id');

        // The company access fence first (security writers wait for us or are seen by us), then the actor as stored now. Nothing is trusted from the held user.
        app(CompanyAccessFence::class)->lock();
        $this->requireCurrentActor($actor, $duties);

        $event = HandoverReleaseEvent::query()->find($eventId);
        $acceptanceId = $event === null ? null : ReleaseLineage::acceptanceIdOfStage($event->stage_id);
        if ($event === null || $acceptanceId === null || $event->purpose !== HandoverReleaseEvent::PURPOSE_SERVICE_JOB_RECEIPT) {
            return new JobExecutionContext([], false, false);
        }
        try {
            $subject = $this->access->lockSource($acceptanceId);
        } catch (ModelNotFoundException) {
            return new JobExecutionContext([], false, false);
        }
        // The fences may have made us WAIT: a revocation, suspension or scope change that committed meanwhile applies now, before anything is proved or returned.
        $this->requireCurrentActor($actor, $duties);
        if ((int) $subject->acceptance->company_id !== $company || ! $this->relationHolds($job, $event, $subject)) {
            return new JobExecutionContext([], false, false);
        }
        // Re-prove against the CURRENT head of the line's completion basis and the CURRENT family authorities, and compare with what the release pinned: any change of basis,
        // scope or family contract version makes fresh work unready (the Job itself stays readable).
        $proof = $this->applicability->evaluate($this->bases->find($subject->acceptanceLine));
        $pinned = $event->relied_on['applicability']['pin'] ?? null;
        $vector = [
            'release_event' => (string) $event->id,
            'condition' => $event->condition_identity,
            'applicability' => $proof['status'].':'.$proof['version'],
            'applicability_pin' => $proof['pin'],
            'acceptance' => (string) $subject->acceptance->id,
        ];

        // Eligible: this actor holds the exact duty and the accepted source is the one this Job came from. Ready for fresh work only while the no-obligation proof still holds.
        return new JobExecutionContext($vector, true, $proof['status'] === 'NOT_APPLICABLE' && is_string($pinned) && hash_equals($pinned, $proof['pin']));
    }

    /**
     * The person as stored NOW: an active member of this company holding every exact duty of the operation, each by its own ALL scope. Both the member and the duties are read
     * again from the database on every call (a revocation never rides a cached user). Judged before the source rows are locked and again after, because the locks can wait.
     *
     * @param  list<string>  $duties
     */
    private function requireCurrentActor(User $actor, array $duties): void
    {
        $fresh = User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($actor->getKey())->first();
        if ($fresh === null || $fresh->status !== MemberStatus::Active || ! JobAccess::mayAll($fresh, ...$duties)) {
            throw new AuthorizationException('You are not permitted to change this Job.');
        }
    }

    /** The Job's one baseline component names this release event and the accepted line that is locked: the source the verifier proved is the one the Job was released from. */
    private function relationHolds(ServiceJob $job, HandoverReleaseEvent $event, HandoverSubject $subject): bool
    {
        return DB::table('job_baseline_components')->where('company_id', $job->company_id)->where('job_id', $job->id)
            ->where('release_event_id', $event->id)->where('issued_line_id', $subject->line->id)->exists();
    }
}
