<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/**
 * Revive the same pursuit (ADR 0107). The earlier closure stays in the history; the stage, owner and everything else are as they were. A genuinely new
 * buying event is a new opportunity, not a reopening.
 */
final class ReopenOpportunity
{
    public function __invoke(Opportunity $opportunity, ?string $reason, User $actor): Opportunity
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_CLOSE, 'reopen an opportunity');
        SalesRecords::requireCompanyActor($actor);
        $reason = SalesRecords::requireReason($reason, 'reopen an opportunity');

        return DB::transaction(function () use ($opportunity, $reason, $actor): Opportunity {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->outcome === OpportunityOutcome::Open) {
                return $locked;
            }
            if ($locked->outcome === OpportunityOutcome::Won) {
                throw new DomainInvariantException('A won opportunity is not reopened here.', 'outcome', 'OPPORTUNITY_WON');
            }
            $previous = ['outcome' => $locked->outcome->value, 'closed_at' => $locked->closed_at?->toIso8601String(), 'closed_by' => $locked->closed_by, 'closure_reason' => $locked->closure_reason];
            $locked->update(['outcome' => OpportunityOutcome::Open, 'closed_at' => null, 'closed_by' => null, 'closure_reason' => null]);
            SalesRecords::record($actor, SalesEvent::REOPENED, null, $locked, $reason, ['previous_closure' => $previous]);

            return $locked->refresh();
        });
    }
}
