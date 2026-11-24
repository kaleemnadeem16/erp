<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\HandoverAllocation;
use App\Modules\Sales\Models\HandoverPlan;
use App\Modules\Sales\Models\HandoverReleaseEvent;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use Illuminate\Support\Facades\DB;

/**
 * Where an accepted service stands: whether a handover was prepared for it and, if so, the ids needed to open it and whether it was released. Same access as reading the plan
 * (acceptance view + quotation view, decided under the access fence and the source locks); an acceptance the person cannot see is 404 like any other unknown one. Reads only; it
 * is not a directory (one acceptance at a time) and returns no Job, text, evidence or money.
 */
final class ReadHandoverStatus
{
    public function __construct(private readonly HandoverAccess $access) {}

    /** @return array{acceptance_id:int,prepared:bool,plan_id:int|null,stage_id:int|null,release_performed:bool} */
    public function __invoke(User $actor, int $acceptanceId): array
    {
        HandoverAccess::early($actor, false);

        return DB::transaction(function () use ($actor, $acceptanceId): array {
            $this->access->lock($actor, $acceptanceId, false);
            $plan = HandoverPlan::query()->where('acceptance_id', $acceptanceId)->first();
            $stage = $plan === null ? null : HandoverStage::query()->whereIn('allocation_id', HandoverAllocation::query()->where('plan_id', $plan->id)->select('id'))->first();

            return ['acceptance_id' => $acceptanceId, 'prepared' => $plan !== null && $stage !== null, 'plan_id' => $plan === null || $stage === null ? null : (int) $plan->id,
                'stage_id' => $stage === null ? null : (int) $stage->id,
                'release_performed' => $stage !== null && HandoverReleaseEvent::query()->where(['stage_id' => $stage->id, 'purpose' => HandoverReleaseEvent::PURPOSE_SERVICE_JOB_RECEIPT])->exists()];
        });
    }
}
