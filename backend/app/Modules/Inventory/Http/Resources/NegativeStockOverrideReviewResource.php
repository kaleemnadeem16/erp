<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\NegativeStockOverrideReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NegativeStockOverrideReview */
class NegativeStockOverrideReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NegativeStockOverrideReview $review */
        $review = $this->resource;

        return [
            'id' => $review->id,
            'outcome' => $review->outcome->value,
            'notes' => $review->notes,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at->toIso8601String(),
        ];
    }
}
