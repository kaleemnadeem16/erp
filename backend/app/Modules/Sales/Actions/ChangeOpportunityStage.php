<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/** Move an open opportunity to another active stage. History keeps both stage names as they were (ADR 0107). Moving to the stage it is already in changes nothing. */
final class ChangeOpportunityStage
{
    public function __invoke(Opportunity $opportunity, int $stageId, User $actor): Opportunity
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_MANAGE, 'move an opportunity');

        return DB::transaction(function () use ($opportunity, $stageId, $actor): Opportunity {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->outcome !== OpportunityOutcome::Open) {
                throw new DomainInvariantException('Reopen a closed opportunity before moving it.', 'outcome', 'OPPORTUNITY_CLOSED');
            }
            if ($locked->stage_id === $stageId) {
                return $locked;
            }
            $to = SalesRecords::activeStage($stageId);
            $from = SalesStage::query()->find($locked->stage_id);
            $locked->update(['stage_id' => $to->id]);
            SalesRecords::record($actor, SalesEvent::STAGE_CHANGED, null, $locked, null, ['from_stage_id' => $from?->id, 'from_stage_name' => $from?->name, 'to_stage_id' => $to->id, 'to_stage_name' => $to->name]);

            return $locked->refresh();
        });
    }
}
