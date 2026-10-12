<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\OpportunityAttributionCorrection;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Support\CrmOperation;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use App\Support\CompanyContext;

/**
 * Correct who the record says was responsible, for one recorded owner fact of a CLOSED opportunity (ADR 0112, 0114), without reopening it. The
 * correction is a new append-only fact that points at the source event and at the current head of that fact's chain; the opportunity row, the source
 * event, the current owner and every other fact are untouched. The corrected member may be a former colleague who has since left: this is attribution,
 * not a new assignment. Authority is the dedicated duty and an independent right to read the pipeline, each by its own scope.
 */
final class CorrectOpportunityAttribution
{
    /** @return array{opportunity: Opportunity, operation: array<string, mixed>, replayed: bool} */
    public function __invoke(Opportunity $opportunity, int $targetEventId, string $targetField, int $assertedOwnerId, ?int $expectedPredecessorId, ?int $expectedClosureEventId, ?string $reason, ?string $requestKey, User $actor): array
    {
        SalesAccess::requireCorrectAttribution($actor);
        SalesRecords::requireCompanyActor($actor);
        $reason = SalesRecords::requireReason($reason, 'correct recorded responsibility');
        if (! in_array($targetField, [OpportunityAttributionCorrection::OWNER_ID, OpportunityAttributionCorrection::TO_OWNER_ID], true)) {
            throw new DomainInvariantException('Choose a recorded owner to correct.', 'target_field', 'ATTRIBUTION_TARGET_UNSUPPORTED');
        }

        $ran = CrmOperation::run(
            $actor, 'OPPORTUNITY_ATTRIBUTION_CORRECT', $requestKey,
            ['target_event_id' => $targetEventId, 'target_field' => $targetField, 'asserted_owner_id' => $assertedOwnerId, 'expected_predecessor_id' => $expectedPredecessorId,
                'expected_closure_event_id' => $expectedClosureEventId, 'reason' => $reason],
            null, $opportunity->id,
            fn () => Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail(),
            function () use ($opportunity, $targetEventId, $targetField, $assertedOwnerId, $expectedPredecessorId, $expectedClosureEventId, $reason, $actor): array {
                $locked = Opportunity::query()->whereKey($opportunity->id)->firstOrFail();
                if ($locked->outcome === OpportunityOutcome::Open) {
                    throw new DomainInvariantException('This pursuit was reopened, so its recorded responsibility is no longer corrected here.', 'outcome', 'ATTRIBUTION_REQUIRES_CLOSED');
                }
                if ($locked->latestClosureEventId() !== $expectedClosureEventId) {
                    throw new DomainInvariantException('This opportunity was closed again since you opened it; review it and confirm again.', 'expected_closure_event_id', 'STALE_EVIDENCE');
                }
                $event = SalesHistory::query()->where('opportunity_id', $locked->id)->whereKey($targetEventId)->first();
                $recorded = $event === null ? null : self::recordedOwner($event, $targetField);
                if ($recorded === null) {
                    throw new DomainInvariantException('Only a recorded owner — who the deal was created for, or who it was reassigned to — can be corrected.', 'target_event_id', 'ATTRIBUTION_TARGET_UNSUPPORTED');
                }
                /** @var OpportunityAttributionCorrection|null $head */
                $head = OpportunityAttributionCorrection::query()->where('opportunity_id', $locked->id)->where('target_event_id', $targetEventId)->where('target_field', $targetField)
                    ->whereNotIn('id', OpportunityAttributionCorrection::query()->whereNotNull('predecessor_id')->select('predecessor_id'))->first();
                if (($head?->id) !== $expectedPredecessorId) {
                    throw new DomainInvariantException('This responsibility was corrected since you opened it; review the current correction and confirm again.', 'expected_predecessor_id', 'STALE_EVIDENCE');
                }
                // Attribution may name a former colleague, but only a person of this company: no foreign or unresolvable identity.
                if (! User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($assertedOwnerId)->exists()) {
                    throw new DomainInvariantException('That person is not a member of this company.', 'asserted_owner_id', 'OWNER_NOT_ELIGIBLE');
                }
                $effective = $head === null ? $recorded : $head->asserted_owner_id;
                if ($effective === $assertedOwnerId) {
                    return ['version' => 1, 'opportunity_id' => $locked->id, 'correction_id' => null, 'head_id' => $head?->id, 'changed' => false];
                }
                $correction = OpportunityAttributionCorrection::create([
                    'opportunity_id' => $locked->id, 'target_event_id' => $targetEventId, 'target_field' => $targetField, 'predecessor_id' => $head?->id,
                    'recorded_owner_id' => $recorded, 'asserted_owner_id' => $assertedOwnerId, 'actor_id' => $actor->id, 'recorded_at' => now(), 'reason' => $reason,
                ]);
                // A reference to the evidence in the opportunity's history; the opportunity row itself is not written.
                SalesRecords::record($actor, SalesEvent::OPPORTUNITY_ATTRIBUTION_CORRECTED, null, $locked, $reason, [
                    'correction_id' => $correction->id, 'target_event_id' => $targetEventId, 'target_field' => $targetField, 'asserted_owner_id' => $assertedOwnerId,
                ]);

                return ['version' => 1, 'opportunity_id' => $locked->id, 'correction_id' => $correction->id, 'head_id' => $correction->id, 'changed' => true];
            },
        );

        return ['opportunity' => Opportunity::query()->findOrFail($opportunity->id), 'operation' => $ran['result'], 'replayed' => $ran['replayed']];
    }

    /** The owner that event recorded for that field, or null when the event is not a supported owner fact. */
    private static function recordedOwner(SalesHistory $event, string $field): ?int
    {
        $details = $event->details;
        if ($event->event === SalesEvent::OPPORTUNITY_CREATED && $field === OpportunityAttributionCorrection::OWNER_ID && isset($details['owner_id'])) {
            return (int) $details['owner_id'];
        }
        if ($event->event === SalesEvent::OWNER_CHANGED && $field === OpportunityAttributionCorrection::TO_OWNER_ID && isset($details['to_owner_id'])) {
            return (int) $details['to_owner_id'];
        }

        return null;
    }
}
