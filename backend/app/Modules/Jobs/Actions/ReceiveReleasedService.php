<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobActivity;
use App\Modules\Jobs\Models\JobBaseline;
use App\Modules\Jobs\Models\JobBaselineComponent;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobHandoverOrigin;
use App\Modules\Jobs\Models\JobSalesOrigin;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Numbering\JobNumbering;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\ReleasedServiceInput;
use App\Modules\Jobs\Support\ReleasedServiceValidator;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Sales\Models\HandoverAllocation;
use App\Modules\Sales\Models\HandoverReleaseEvent;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReference;
use App\Modules\Sales\Support\Handover\Release\ReleaseReceiverAuthority;
use App\Modules\Sales\Support\Handover\Release\VerifiedServiceRelease;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Receives one genuinely released Sales service. It is the ONLY writer of a Sales-origin Job and it is not an entry point: it is reachable only through the Sales
 * release coordinator, which calls it INSIDE the release transaction (same connection and session) after the release event exists. It trusts nothing about authority from
 * the argument: it proves the release is current in this very transaction, independently requires `job.receive` of the releasing actor (re-read as a current member),
 * and then writes — numbered Job, generic origin, Sales lineage, RELEASE event, baseline v1, the accepted component and the one default activity — or throws, which rolls the
 * whole release back. It never commits or starts a transaction of its own; a business replay never reaches it again (the coordinator resolves the existing release first),
 * and the database refuses a second Job for the same release event, so a fresh key, actor or retry cannot create a duplicate Job, number or claim.
 */
final class ReceiveReleasedService implements ReleasedServiceReceiver, ReleaseReceiverAuthority
{
    public const SOURCE_CONTRACT = 'sales-handover-release-v1';

    public const COMPLETION_RULE = 'SIMPLE_ATTESTATION_V1';

    public function __construct(private readonly NumberingService $numbering, private readonly ReleasedServiceValidator $validator) {}

    public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
    {
        $release->assertCurrent();
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A released service is received only inside the release transaction.');
        }
        if ($release->companyId !== app(CompanyContext::class)->id()) {
            throw new AuthorizationException('You are not permitted to receive this service.');
        }
        // The releasing actor is judged again, independently of Sales: a currently active member holding `job.receive` with company-wide scope, duty ACTIVE.
        $actor = User::query()->where('company_id', $release->companyId)->whereKey($release->actorId)->first();
        $this->assertMayReceive($actor);
        $event = HandoverReleaseEvent::query()->whereKey($release->releaseEventId)->first();
        if ($event === null || (int) $event->stage_id !== $release->stageId || (int) $event->baseline_id !== $release->baselineId || (int) $event->basis_id !== $release->basis->basisId) {
            throw new LogicException('The release event does not match the verified release.');
        }
        if (JobSalesOrigin::query()->where('release_event_id', $event->id)->exists()) {
            throw new LogicException('This release event has already produced a Job.');
        }
        $allocationId = (int) HandoverStage::query()->whereKey($release->stageId)->value('allocation_id');
        if ($allocationId === 0 || ! HandoverAllocation::query()->whereKey($allocationId)->exists()) {
            throw new LogicException('The release stage has no allocation.');
        }

        $now = CarbonImmutable::now();
        $code = $this->numbering->allocate(JobNumbering::contextFor($release->releaseEventId, $now, $release->actorId))->number;
        $fingerprint = hash('sha256', implode("\n", [$release->deliverableIdentity, $release->deliverableText, $release->basis->conditionIdentity(), $release->basis->agreedText]));
        $input = new ReleasedServiceInput($code, self::SOURCE_CONTRACT, $release->purpose, 'stage:'.$release->stageId, (string) $event->intent_hash, $fingerprint,
            $release->deliverableText, self::COMPLETION_RULE);
        $this->validator->validate($input);

        $job = ServiceJob::query()->create(['code' => $input->jobCode, 'state' => 'RELEASED', 'generation' => 1]);
        JobEvent::query()->create([
            'job_id' => $job->id, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurred_at' => $now, 'occurrence_precision' => 'EXACT', 'recorded_at' => $now, 'actor_id' => $release->actorId, 'evidence_version' => 1,
        ]);
        $baseline = JobBaseline::query()->create([
            'job_id' => $job->id, 'version' => 1, 'fingerprint' => $input->baselineFingerprint, 'scope_description' => $input->scopeDescription,
            'completion_rule_version' => $input->completionRuleVersion, 'recorded_at' => $now,
        ]);
        JobHandoverOrigin::query()->create([
            'job_id' => $job->id, 'source_contract_version' => $input->sourceContractVersion, 'target_purpose' => $input->targetPurpose,
            'business_identity' => $input->businessIdentity, 'intent_hash' => $input->intentHash, 'recorded_at' => $now,
        ]);
        JobSalesOrigin::query()->create(['job_id' => $job->id, 'release_event_id' => $event->id]);
        JobBaselineComponent::query()->create([
            'job_id' => $job->id, 'baseline_id' => $baseline->id, 'release_event_id' => $event->id, 'allocation_id' => $allocationId, 'issued_line_id' => $release->issuedLineId,
            'basis_id' => $release->basis->basisId, 'deliverable_identity' => $release->deliverableIdentity, 'deliverable_text' => $release->deliverableText,
            'agreed_conditions' => $release->basis->agreedText, 'condition_identity' => $release->basis->conditionIdentity(),
        ]);
        JobActivity::query()->create(['job_id' => $job->id, 'kind' => 'DEFAULT', 'description' => $release->deliverableText]);

        return new ReleasedServiceReference((int) $job->id, (string) $job->code);
    }

    /** Asked by the release coordinator at the start of every request, replays included, so a revoked duty or a suspended member never gets a Job reference back. */
    public function assertMayReceive(?User $actor): void
    {
        if (! JobAccess::may($actor, 'job.receive')) {
            throw new AuthorizationException('You are not permitted to receive a released service.');
        }
    }

    public function referenceFor(int $releaseEventId): ?ReleasedServiceReference
    {
        $row = JobSalesOrigin::query()->where('release_event_id', $releaseEventId)->first();
        if ($row === null) {
            return null;
        }
        $job = ServiceJob::query()->whereKey($row->job_id)->first();

        return $job === null ? null : new ReleasedServiceReference((int) $job->id, (string) $job->code);
    }
}
