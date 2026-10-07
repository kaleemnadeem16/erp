<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Item $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'type' => $item->type->value,
            'stock_uom_id' => $item->stock_uom_id,
            'quantity_precision' => $item->quantity_precision,
            'is_stocked' => $item->is_stocked,
            'is_purchasable' => $item->is_purchasable,
            'is_sellable' => $item->is_sellable,
            'valuation_method' => $item->valuation_method->value,
            'avco_pool_scope' => $item->avco_pool_scope?->value,
            'tracking' => $item->tracking->value,
            'package_tracking_mode' => $item->package_tracking_mode->value,
            'category_id' => $item->category_id,
            'income_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $item->income_account_id),
            'expense_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $item->expense_account_id),
            'inventory_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $item->inventory_account_id),
            /** @var array<string, string|int|float|bool>|null */
            'custom_fields' => (object) ($item->custom_fields ?? []),
            'status' => $item->status->value,
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($item->category)),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
