<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Support\CrmOperation;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/**
 * Qualify, disqualify and revive an enquiry (ADR 0108). Every decision is a history row with its reason; a revived enquiry keeps what was decided before.
 * Qualification consumes no commercial evidence and creates no financial account.
 */
final class DecideLead
{
    public function qualify(Lead $lead, User $actor, ?string $reason = null): Lead
    {
        return $this->decide($lead, $actor, function (Lead $locked) use ($actor, $reason): void {
            if ($locked->status === LeadStatus::Qualified) {
                return;
            }
            if ($locked->status === LeadStatus::Disqualified) {
                throw new DomainInvariantException('Revive a disqualified enquiry before qualifying it.', 'status', 'LEAD_DISQUALIFIED');
            }
            $locked->update(['status' => LeadStatus::Qualified, 'qualified_at' => now(), 'qualified_by' => $actor->id]);
            SalesRecords::record($actor, SalesEvent::LEAD_QUALIFIED, $locked, null, $reason);
        });
    }

    /**
     * Disqualify the enquiry itself (ADR 0111). Opportunities already derived from it are separate pursuits and are not touched, merged or closed.
     * The decision is confirmed against the latest decision the caller saw (`$expectedDecisionEventId`, null when none), so a conversion, qualification or
     * revival that happened meanwhile is reviewed rather than overwritten, and it is keyed so that retrying it records nothing twice.
     *
     * @return array{lead: Lead, operation: array<string, mixed>, replayed: bool}
     */
    public function disqualify(Lead $lead, User $actor, ?string $reason, ?string $requestKey, ?int $expectedDecisionEventId): array
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'decide about an enquiry');
        SalesRecords::requireCompanyActor($actor);
        $reason = SalesRecords::requireReason($reason, 'disqualify an enquiry');

        $ran = CrmOperation::run(
            $actor, 'LEAD_DISQUALIFY', $requestKey,
            ['reason' => $reason, 'expected_decision_event_id' => $expectedDecisionEventId], $lead->id, null,
            fn () => Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail(),
            function () use ($lead, $actor, $reason, $expectedDecisionEventId): array {
                $locked = Lead::query()->whereKey($lead->id)->firstOrFail();
                if ($locked->latestDecisionEventId() !== $expectedDecisionEventId) {
                    throw new DomainInvariantException('This enquiry was decided or converted since you opened it; review it and decide again.', 'expected_decision_event_id', 'STALE_EVIDENCE');
                }
                if ($locked->status === LeadStatus::Disqualified) {
                    return ['version' => 1, 'lead_id' => $locked->id, 'status' => LeadStatus::Disqualified->value, 'decision_event_id' => $expectedDecisionEventId, 'changed' => false];
                }
                // What was decided before stays in the history: the projection below clears it.
                $details = ['prior_status' => $locked->status->value, 'prior_qualified_at' => $locked->qualified_at?->toIso8601String(), 'prior_qualified_by' => $locked->qualified_by];
                $locked->update(['status' => LeadStatus::Disqualified, 'disqualified_at' => now(), 'disqualified_by' => $actor->id, 'disqualification_reason' => $reason, 'qualified_at' => null, 'qualified_by' => null]);
                $event = SalesRecords::record($actor, SalesEvent::LEAD_DISQUALIFIED, $locked, null, $reason, $details);

                return ['version' => 1, 'lead_id' => $locked->id, 'status' => LeadStatus::Disqualified->value, 'decision_event_id' => $event->id, 'changed' => true];
            },
        );

        return ['lead' => Lead::query()->findOrFail($lead->id), 'operation' => $ran['result'], 'replayed' => $ran['replayed']];
    }

    public function revive(Lead $lead, User $actor, ?string $reason): Lead
    {
        $reason = SalesRecords::requireReason($reason, 'revive an enquiry');

        return $this->decide($lead, $actor, function (Lead $locked) use ($actor, $reason): void {
            if ($locked->status !== LeadStatus::Disqualified) {
                return;
            }
            // What was decided before stays in the history (details), because the row itself returns to "new".
            $details = ['disqualified_at' => $locked->disqualified_at?->toIso8601String(), 'disqualified_by' => $locked->disqualified_by, 'disqualification_reason' => $locked->disqualification_reason];
            $locked->update(['status' => LeadStatus::New, 'disqualified_at' => null, 'disqualified_by' => null, 'disqualification_reason' => null]);
            SalesRecords::record($actor, SalesEvent::LEAD_REVIVED, $locked, null, $reason, $details);
        });
    }

    /** @param \Closure(Lead): void $change */
    private function decide(Lead $lead, User $actor, \Closure $change): Lead
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'decide about an enquiry');
        SalesRecords::requireCompanyActor($actor);

        return DB::transaction(function () use ($lead, $change): Lead {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $change($locked);

            return $locked->refresh();
        });
    }
}
