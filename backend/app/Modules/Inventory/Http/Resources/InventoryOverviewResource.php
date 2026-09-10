<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/v1/inventory-overview — see
 * App\Modules\Inventory\Actions\BuildInventoryOverviewQuery for how each
 * figure is derived. `$resource` here is an Item model carrying five extra
 * raw-selected columns from that query (on_hand_qty, qa_held_qty,
 * available_qty, on_order_qty, shortage_qty) — not real Item attributes.
 *
 * @mixin Item
 *
 * @property string $on_hand_qty
 * @property string $qa_held_qty
 * @property string $available_qty
 * @property string $on_order_qty
 * @property string $shortage_qty
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
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($item->category)),
            'valuation_method' => $item->valuation_method,
            'on_hand_qty' => (string) BigDecimal::of((string) $item->getAttribute('on_hand_qty'))->toScale(4),
            'available_qty' => (string) BigDecimal::of((string) $item->getAttribute('available_qty'))->toScale(4),
            'qa_held_qty' => (string) BigDecimal::of((string) $item->getAttribute('qa_held_qty'))->toScale(4),
            'on_order_qty' => (string) BigDecimal::of((string) $item->getAttribute('on_order_qty'))->toScale(4),
            'shortage_qty' => (string) BigDecimal::of((string) $item->getAttribute('shortage_qty'))->toScale(4),
        ];
    }
}
