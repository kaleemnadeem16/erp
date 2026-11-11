<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Http\Resources\UserResource;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverNegativeOpeningStatus;
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
            /**
             * What the cutover leaves out or carries as an exception, counted from its batches and negative openings. Null unless both
             * `batches` and `negativeOpenings` are included, because the counts need them. The stored `exception_summary` column is not
             * written by any Action and is not read here, so this never depends on it.
             *
             * @var array{total: int, unresolved: int, categories: list<array{code: 'REJECTED_RECORDS'|'NEGATIVE_OPENING_REQUESTED'|'NEGATIVE_OPENING_APPROVED', count: int, unresolved: bool}>}|null
             */
            'exception_summary' => $this->exceptionSummary($cutover),
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

    /**
     * REJECTED_RECORDS are source lines left out of the cutover; NEGATIVE_OPENING_REQUESTED await a decision; NEGATIVE_OPENING_APPROVED are
     * decided and carried into live inventory as shortages. The first two are unresolved. Only categories with a count are listed.
     *
     * @return array{total: int, unresolved: int, categories: list<array{code: string, count: int, unresolved: bool}>}|null
     */
    private function exceptionSummary(InventoryCutover $cutover): ?array
    {
        if (! $cutover->relationLoaded('batches') || ! $cutover->relationLoaded('negativeOpenings')) {
            return null;
        }
        $counts = [
            'REJECTED_RECORDS' => (int) $cutover->batches->sum('rejected_record_count'),
            'NEGATIVE_OPENING_REQUESTED' => $cutover->negativeOpenings->where('status', InventoryCutoverNegativeOpeningStatus::Requested)->count(),
            'NEGATIVE_OPENING_APPROVED' => $cutover->negativeOpenings->where('status', InventoryCutoverNegativeOpeningStatus::Approved)->count(),
        ];
        $categories = [];
        foreach ($counts as $code => $count) {
            if ($count > 0) {
                $categories[] = ['code' => $code, 'count' => $count, 'unresolved' => $code !== 'NEGATIVE_OPENING_APPROVED'];
            }
        }

        return [
            'total' => array_sum($counts),
            'unresolved' => array_sum(array_column(array_filter($categories, fn (array $c): bool => $c['unresolved']), 'count')),
            'categories' => $categories,
        ];
    }
}
