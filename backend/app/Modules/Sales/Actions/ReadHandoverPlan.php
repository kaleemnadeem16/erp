<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\HandoverAllocation;
use App\Modules\Sales\Models\HandoverBaseline;
use App\Modules\Sales\Models\HandoverPlan;
use App\Modules\Sales\Models\HandoverReadinessEvidence;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

/** Safe immutable plan under current acceptance-view rules. Review token/digests and confidential source money never leave this projection. */
final class ReadHandoverPlan
{
    public function __construct(private readonly HandoverAccess $access) {}

    /** @return array<string,mixed> */
    public function __invoke(User $actor, int $planId): array
    {
        HandoverAccess::early($actor, false);

        return DB::transaction(function () use ($actor, $planId): array {
            // Decide authority before even looking up a plan; no hidden-id existence oracle.
            CustomerDecisionAccess::decide($actor, PermissionVocabulary::CUSTOMER_DECISION_VIEW, 'read handover plans');
            $plan = HandoverPlan::findOrFail($planId);
            $this->access->lock($actor, $plan->acceptance_id, false);
            $a = HandoverAllocation::where('plan_id', $plan->id)->firstOrFail();
            $stage = HandoverStage::where('allocation_id', $a->id)->firstOrFail();
            $baseline = HandoverBaseline::where('stage_id', $stage->id)->firstOrFail();
            $evidence = HandoverReadinessEvidence::where('stage_id', $stage->id)->firstOrFail();

            return ['id' => $plan->id, 'acceptance_id' => $plan->acceptance_id, 'path' => $plan->path, 'prepared_by' => $plan->prepared_by, 'recorded_at' => $plan->recorded_at->toIso8601String(),
                'allocation_id' => $a->id, 'stage_id' => $stage->id, 'baseline_id' => $baseline->id, 'issued_line_id' => $baseline->issued_line_id,
                'state' => 'PREPARED', 'operational' => array_intersect_key($baseline->operational_snapshot, array_flip(['deliverable_identity', 'description', 'measure_id', 'quantity', 'completion_conditions', 'location_kind', 'instructions', 'contact', 'site'])), 'readiness_at_preparation' => $evidence->assessment,
                'withheld' => ['commercial_prices', 'commercial_quantities', 'tax_fx_evidence', 'costs_and_margins'], 'release_performed' => false];
        });
    }
}
