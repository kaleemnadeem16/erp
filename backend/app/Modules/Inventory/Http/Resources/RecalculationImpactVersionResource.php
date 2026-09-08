<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\RecalculationImpactVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Includes computed convenience flags (required_approval_count,
 * is_rejected, is_fully_approved, has_unexpired_full_approval, is_decided)
 * straight from the model's own methods, rather than pushing the
 * expiry-comparison/distinct-actor-counting logic behind them onto the
 * frontend — this domain's approval state is genuinely more involved than
 * physical counts/cutover/negative-stock overrides, where the client could
 * safely derive display status itself.
 *
 * @mixin RecalculationImpactVersion
 */
class RecalculationImpactVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecalculationImpactVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->id,
            'recalculation_request_id' => $version->recalculation_request_id,
            'version_no' => $version->version_no,
            'avco_pool_id' => $version->avco_pool_id,
            'original_qty_on_hand' => $version->original_qty_on_hand,
            'original_inventory_value' => $version->original_inventory_value,
            'original_avg_unit_cost' => $version->original_avg_unit_cost,
            'counterfactual_qty_on_hand' => $version->counterfactual_qty_on_hand,
            'counterfactual_inventory_value' => $version->counterfactual_inventory_value,
            'counterfactual_avg_unit_cost' => $version->counterfactual_avg_unit_cost,
            'valuation_adjustment' => $version->valuation_adjustment,
            'risk_classification' => $version->risk_classification->value,
            'movement_impact' => $version->movement_impact,
            'computed_at' => $version->computed_at->toIso8601String(),
            'is_stale' => $version->is_stale,
            'required_approval_count' => $version->requiredApprovalCount(),
            'is_rejected' => $version->isRejected(),
            'is_fully_approved' => $version->isFullyApproved(),
            'has_unexpired_full_approval' => $version->hasUnexpiredFullApproval(),
            'is_decided' => $version->isDecided(),
            'approvals' => RecalculationApprovalResource::collection($this->whenLoaded('approvals')),
            'execution' => $this->whenLoaded('execution', fn () => $version->execution === null ? null : new RecalculationExecutionResource($version->execution)),
            'regeneration_review' => $this->whenLoaded('regenerationReview', fn () => $version->regenerationReview === null ? null : new RecalculationRegenerationReviewResource($version->regenerationReview)),
        ];
    }
}
