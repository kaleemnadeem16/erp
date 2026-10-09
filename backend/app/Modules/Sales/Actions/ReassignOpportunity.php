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
 * Make another active member accountable for an opportunity (ADR 0106). The previous owner stays in the history, including one who has since been suspended;
 * the new owner is validated here and is granted nothing — ownership is responsibility, not access.
 */
final class ReassignOpportunity
{
    public function __invoke(Opportunity $opportunity, int $ownerId, User $actor, ?string $reason = null): Opportunity
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_ASSIGN, 'reassign an opportunity');
        SalesRecords::requireCompanyActor($actor);

        return DB::transaction(function () use ($opportunity, $ownerId, $actor, $reason): Opportunity {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->outcome !== OpportunityOutcome::Open) {
                throw new DomainInvariantException('Reopen a closed opportunity before changing who is responsible for it.', 'outcome', 'OPPORTUNITY_CLOSED');
            }
            if ($locked->owner_id === $ownerId) {
                return $locked;
            }
            $owner = SalesRecords::eligibleMember($ownerId, 'owner_id');
            $from = $locked->owner_id;
            $locked->update(['owner_id' => $owner->id]);
            SalesRecords::record($actor, SalesEvent::OWNER_CHANGED, null, $locked, $reason === null ? null : trim($reason), ['from_owner_id' => $from, 'to_owner_id' => $owner->id]);

            return $locked->refresh();
        });
    }
}
