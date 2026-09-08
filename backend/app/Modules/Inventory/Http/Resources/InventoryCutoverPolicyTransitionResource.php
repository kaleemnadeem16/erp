<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventoryCutoverPolicyTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryCutoverPolicyTransition */
class InventoryCutoverPolicyTransitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventoryCutoverPolicyTransition $transition */
        $transition = $this->resource;

        return [
            'id' => $transition->id,
            'item_id' => $transition->item_id,
            'source_valuation_method' => $transition->source_valuation_method->value,
            'source_avco_scope' => $transition->source_avco_scope?->value,
            'source_pool_identity' => $transition->source_pool_identity,
            'target_valuation_method' => $transition->target_valuation_method->value,
            'target_avco_scope' => $transition->target_avco_scope?->value,
            'effective_at' => $transition->effective_at->toIso8601String(),
            'revaluation_basis' => $transition->revaluation_basis,
        ];
    }
}
