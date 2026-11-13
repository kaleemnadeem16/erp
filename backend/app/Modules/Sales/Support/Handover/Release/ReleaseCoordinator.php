<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Models\HandoverBaseline;
use App\Modules\Sales\Models\HandoverReleaseEvent;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use LogicException;

/**
 * The single atomic release transaction: actor authority -> source locks (authority head, acceptance, case, claim heads, revision; the same order as preparation) ->
 * replay/business-uniqueness -> basis + readiness -> immutable release event -> the Jobs receiver, all on one connection in one transaction. Any failure after any write
 * rolls everything back. A release consumes no acceptance claim: it only reads the claim heads under their locks. The receiver is a port Jobs binds; unbound, every release refuses.
 *
 * @internal Use {@see ReleaseHandoverComponent}.
 */
final class ReleaseCoordinator
{
    public function __construct(
        private readonly CustomerDecisionOperation $operations,
        private readonly HandoverAccess $access,
        private readonly ServiceReleaseReadiness $readiness,
        private readonly SalesCompletionBasis $bases,
    ) {}

    /** @return array{release_event_id:int,case_id:int,job_id:int,job_code:string,replayed:bool} */
    public function release(User $actor, ?string $requestKey, ReleaseIntent $intent): array
    {
        HandoverAccess::early($actor, false, P::HANDOVER_RELEASE);
        if (! app()->bound(ReleasedServiceReceiver::class)) {
            throw R::make(R::RELEASE_RECEIVER_UNAVAILABLE, 'No Jobs receiver is available, so a service cannot be released.');
        }
        // The receiver's own authority is judged on EVERY request, replays included: a revoked `job.receive` or a suspended member never gets the Job reference back.
        $receiver = app(ReleasedServiceReceiver::class);
        // Static analysis resolves the container binding to the Jobs receiver; tests and other receivers may bind a port without this companion, hence the runtime check.
        if ($receiver instanceof ReleaseReceiverAuthority) { // @phpstan-ignore instanceof.alwaysTrue
            $receiver->assertMayReceive($actor);
        }
        $acceptanceId = ReleaseLineage::acceptanceIdOfStage($intent->stageId) ?? throw R::make(R::ACCEPTANCE_NOT_FOUND, 'The handover stage was not found.');

        $outcome = $this->operations->run($actor, 'handover.release', $requestKey, $intent->payload(),
            fn (): HandoverSubject => $this->access->lock($actor, $acceptanceId, false, P::HANDOVER_RELEASE),
            fn (HandoverSubject $s): array => $this->work($actor, $requestKey, $intent, $s));

        return ['release_event_id' => (int) $outcome['result']['release_event_id'], 'case_id' => (int) $outcome['result']['case_id'], 'job_id' => (int) $outcome['result']['job_id'],
            'job_code' => (string) $outcome['result']['job_code'], 'replayed' => $outcome['replayed']];
    }

    /** @return array<string,mixed> */
    private function work(User $actor, ?string $requestKey, ReleaseIntent $intent, HandoverSubject $s): array
    {
        $receiver = app(ReleasedServiceReceiver::class);
        $stage = HandoverStage::query()->findOrFail($intent->stageId);
        $baseline = HandoverBaseline::query()->where('stage_id', $stage->id)->firstOrFail();

        $existing = HandoverReleaseEvent::query()->where(['stage_id' => $stage->id, 'purpose' => HandoverReleaseEvent::PURPOSE_SERVICE_JOB_RECEIPT])->first();
        if ($existing !== null) {
            // Business uniqueness: the same stage and purpose has exactly one release, whatever the key or actor. The same business intent resolves to it; any other is refused.
            $reference = $receiver->referenceFor($existing->id);
            if ($existing->intent_hash !== $intent->businessHash() || $reference === null) {
                throw R::make(R::RELEASE_ALREADY_RECORDED, 'This service stage has already been released.');
            }

            return $this->result($existing->id, $s, $reference);
        }

        $basis = $this->bases->resolve($s->acceptanceLine);
        if ($basis->basisId !== $intent->basisId) {
            throw R::make(R::PREVIEW_TOKEN_STALE, 'The agreed completion conditions changed after this release was reviewed.');
        }
        $review = $this->readiness->assess($s, $stage, $baseline, $basis);
        if ($review->fingerprint !== $intent->readinessFingerprint) {
            throw R::make(R::PREVIEW_TOKEN_STALE, 'The release facts changed after this release was reviewed.');
        }
        if (! $review->ready()) {
            throw R::make(R::HANDOVER_NOT_READY, 'Release is blocked: '.implode(', ', $review->blockers).'.');
        }

        $context = HandoverTransactionContext::capture();
        $event = HandoverReleaseEvent::query()->create([
            'stage_id' => $stage->id, 'baseline_id' => $baseline->id, 'basis_id' => $basis->basisId, 'purpose' => HandoverReleaseEvent::PURPOSE_SERVICE_JOB_RECEIPT,
            'actor_id' => $actor->id, 'request_key' => trim((string) $requestKey), 'intent_hash' => $intent->businessHash(), 'condition_identity' => $basis->conditionIdentity(),
            'condition_version' => $basis->version, 'cost_required' => $review->cost->required, 'cost_status' => $review->cost->status, 'cost_policy_version' => $review->cost->policyVersion,
            'relied_on' => $review->relied,
        ]);
        $verified = VerifiedServiceRelease::issue(app(CompanyContext::class)->id(), $event->id, $stage->id, $baseline->id, $s->acceptance->id, $s->acceptanceLine->id, $s->line->id,
            $event->purpose, (string) $baseline->operational_snapshot['deliverable_identity'], $basis->deliverableText, $basis, $this->operational($baseline), $actor->id, $context);
        $reference = $receiver->receive($verified);

        // Defence in depth: the receiver must still be in this transaction and must have durably linked exactly this release to the Job it reports.
        $context->assertCurrent();
        $linked = $receiver->referenceFor($event->id);
        if ($linked === null || $linked->jobId !== $reference->jobId || $linked->jobCode !== $reference->jobCode) {
            throw new LogicException('The Jobs receiver did not link the release to the Job it reported.');
        }

        return $this->result($event->id, $s, $reference);
    }

    /** @return array<string,mixed> */
    private function operational(HandoverBaseline $baseline): array
    {
        $snapshot = $baseline->operational_snapshot;

        return ['location_kind' => $snapshot['location_kind'] ?? null, 'instructions' => $snapshot['instructions'] ?? '', 'contact' => $snapshot['contact'] ?? null, 'site' => $snapshot['site'] ?? null];
    }

    /** @return array<string,mixed> */
    private function result(int $eventId, HandoverSubject $s, ReleasedServiceReference $reference): array
    {
        return ['release_event_id' => $eventId, 'case_id' => $s->case->id, 'job_id' => $reference->jobId, 'job_code' => $reference->jobCode];
    }
}
