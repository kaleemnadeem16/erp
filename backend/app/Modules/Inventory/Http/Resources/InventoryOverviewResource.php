<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/v1/inventory-overview — see
 * App\Modules\Inventory\Actions\BuildInventoryOverviewQuery for how each
 * figure is derived. `$resource` here is an Item model carrying seven extra
 * raw-selected columns from that query (on_hand_qty, qa_held_qty,
 * available_qty, on_order_qty, shortage_qty, on_hand_value,
 * available_value) — not real Item attributes.
 *
 * @mixin Item
 *
 * @property string $on_hand_qty
 * @property string $qa_held_qty
 * @property string $available_qty
 * @property string $on_order_qty
 * @property string $shortage_qty
 * @property string $on_hand_value
 * @property string $available_value
 */
class InventoryOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Item $item */
        $item = $this->resource;

        return [
            'item_id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type,
            'stock_uom_id' => $item->stock_uom_id,
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($item->category)),
            'valuation_method' => $item->valuation_method,
            'tracking' => $item->tracking,
            'description' => $item->description,
            'on_hand_qty' => (string) BigDecimal::of((string) $item->getAttribute('on_hand_qty'))->toScale(4),
            'available_qty' => (string) BigDecimal::of((string) $item->getAttribute('available_qty'))->toScale(4),
            'qa_held_qty' => (string) BigDecimal::of((string) $item->getAttribute('qa_held_qty'))->toScale(4),
            'on_order_qty' => (string) BigDecimal::of((string) $item->getAttribute('on_order_qty'))->toScale(4),
            'shortage_qty' => (string) BigDecimal::of((string) $item->getAttribute('shortage_qty'))->toScale(4),
            // on_hand_value is a straight SUM of already-4-decimal-scale columns
            // (no rounding ever needed); available_value additionally prorates by
            // on_hand_qty in SQL, and Postgres NUMERIC division doesn't preserve a
            // fixed scale — rounding here is the norm, not the exception, for that
            // one (mirrors InventoryOverviewSummaryResource's identical asymmetry).
            'on_hand_value' => $this->when($request->user()?->can('inventory.valuation.view'), fn () => (string) BigDecimal::of((string) $item->getAttribute('on_hand_value'))->toScale(4)),
            'available_value' => $this->when($request->user()?->can('inventory.valuation.view'), fn () => (string) BigDecimal::of((string) $item->getAttribute('available_value'))->toScale(4, RoundingMode::HALF_UP)),
        ];
    }
}
