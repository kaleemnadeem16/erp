<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\RecalculationRegenerationReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecalculationRegenerationReview */
class RecalculationRegenerationReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecalculationRegenerationReview $review */
        $review = $this->resource;

        return [
            'id' => $review->id,
            'impact_version_id' => $review->impact_version_id,
            'invalidation_count' => $review->invalidation_count,
            'escalation' => $review->escalation,
            'notes' => $review->notes,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at->toIso8601String(),
        ];
    }
}
