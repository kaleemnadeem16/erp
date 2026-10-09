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
 * Close pursuit as LOST (unsuccessful business) or ABANDONED (pursuit discontinued), with a reason, actor and time (ADR 0107). Closing changes nothing
 * else: no quote, job, commitment or forecast publication is touched.
 *
 * WON is refused for now. It needs authoritative recorded customer acceptance, which does not exist until quotations do; a manually entered "won" amount
 * would invent a commitment. The same goes for classifying a deal with accepted business as wholly lost — there is none to protect yet.
 */
final class CloseOpportunity
{
    public function __invoke(Opportunity $opportunity, OpportunityOutcome $outcome, ?string $reason, User $actor): Opportunity
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_CLOSE, 'close an opportunity');
        SalesRecords::requireCompanyActor($actor);
        if ($outcome === OpportunityOutcome::Open) {
            throw new DomainInvariantException('Choose how pursuit ended: lost or abandoned.', 'outcome');
        }
        if ($outcome === OpportunityOutcome::Won) {
            throw new DomainInvariantException('A win needs recorded customer acceptance, which is not available yet.', 'outcome', 'WON_REQUIRES_ACCEPTANCE');
        }
        $reason = SalesRecords::requireReason($reason, 'close an opportunity');

        return DB::transaction(function () use ($opportunity, $outcome, $reason, $actor): Opportunity {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->outcome !== OpportunityOutcome::Open) {
                if ($locked->outcome === $outcome) {
                    return $locked;
                }
                throw new DomainInvariantException('This opportunity is already closed; reopen it first.', 'outcome', 'OPPORTUNITY_CLOSED');
            }
            $locked->update(['outcome' => $outcome, 'closed_at' => now(), 'closed_by' => $actor->id, 'closure_reason' => $reason]);
            SalesRecords::record($actor, SalesEvent::CLOSED, null, $locked, $reason, ['outcome' => $outcome->value, 'stage_id' => $locked->stage_id]);

            return $locked->refresh();
        });
    }
}
