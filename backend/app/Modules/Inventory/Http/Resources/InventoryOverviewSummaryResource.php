<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The headline totals above the inventory-overview table — see
 * App\Modules\Inventory\Actions\BuildInventoryOverviewQuery for on_hand_value/
 * available_value, and InventoryOverviewController::summary() for how they're
 * rolled up across the same filtered, unpaginated set the table itself shows.
 * $resource is the plain stdClass row that aggregate query returns, not a model.
 *
 * @property numeric-string $on_hand_value
 * @property numeric-string $available_value
 * @property int $item_count
 * @property int $shortage_item_count
 * @property int $qa_held_item_count
 */
class InventoryOverviewSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // on_hand_value is a straight SUM of already-4-decimal-scale columns
            // (no rounding ever needed); available_value additionally divides by
            // on_hand_qty, and Postgres NUMERIC division doesn't preserve a fixed
            // scale — rounding here is the norm, not the exception, for that one.
            'on_hand_value' => (string) BigDecimal::of($this->on_hand_value)->toScale(4),
            'available_value' => (string) BigDecimal::of($this->available_value)->toScale(4, RoundingMode::HALF_UP),
            'item_count' => (int) $this->item_count,
            'shortage_item_count' => (int) $this->shortage_item_count,
            'qa_held_item_count' => (int) $this->qa_held_item_count,
        ];
    }
}
