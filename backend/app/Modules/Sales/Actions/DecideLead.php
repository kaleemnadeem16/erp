<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\OpportunityOutcome;
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

    public function disqualify(Lead $lead, User $actor, ?string $reason): Lead
    {
        $reason = SalesRecords::requireReason($reason, 'disqualify an enquiry');

        return $this->decide($lead, $actor, function (Lead $locked) use ($actor, $reason): void {
            if ($locked->status === LeadStatus::Disqualified) {
                return;
            }
            if (Opportunity::query()->where('lead_id', $locked->id)->where('outcome', OpportunityOutcome::Open->value)->exists()) {
                throw new DomainInvariantException('This enquiry still has open opportunities; close or pursue them first.', 'status', 'LEAD_HAS_OPEN_OPPORTUNITY');
            }
            $locked->update(['status' => LeadStatus::Disqualified, 'disqualified_at' => now(), 'disqualified_by' => $actor->id, 'disqualification_reason' => $reason, 'qualified_at' => null, 'qualified_by' => null]);
            SalesRecords::record($actor, SalesEvent::LEAD_DISQUALIFIED, $locked, null, $reason);
        });
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
