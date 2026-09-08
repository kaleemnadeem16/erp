<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Http\Resources\UserResource;
use App\Modules\Inventory\Models\InventoryCutover;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryCutover */
class InventoryCutoverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventoryCutover $cutover */
        $cutover = $this->resource;

        return [
            'id' => $cutover->id,
            'mode' => $cutover->mode->value,
            'status' => $cutover->status->value,
            'cutover_at' => $cutover->cutover_at->toIso8601String(),
            'source_system' => $cutover->source_system,
            'target_valuation_policy' => $cutover->target_valuation_policy,
            'control_quantity' => $cutover->control_quantity,
            'control_value' => $cutover->control_value,
            'base_control_value' => $cutover->base_control_value,
            'exception_summary' => $cutover->exception_summary,
            'created_by' => $cutover->created_by,
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($cutover->creator)),
            'created_at' => $cutover->created_at?->toIso8601String(),
            'validated_by' => $cutover->validated_by,
            'validated_at' => $cutover->validated_at?->toIso8601String(),
            'approved_by' => $cutover->approved_by,
            'approved_at' => $cutover->approved_at?->toIso8601String(),
            'finalised_by' => $cutover->finalised_by,
            'finalised_at' => $cutover->finalised_at?->toIso8601String(),
            'batches' => InventoryCutoverBatchResource::collection($this->whenLoaded('batches')),
            'policy_transitions' => InventoryCutoverPolicyTransitionResource::collection($this->whenLoaded('policyTransitions')),
            'negative_openings' => InventoryCutoverNegativeOpeningResource::collection($this->whenLoaded('negativeOpenings')),
        ];
    }
}
