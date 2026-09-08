<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockValuationEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockValuationEntry */
class StockValuationEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockValuationEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'stock_move_id' => $entry->stock_move_id,
            'avco_pool_id' => $entry->avco_pool_id,
            'pool_effect' => $entry->pool_effect,
            'qty' => $entry->qty,
            'unit_cost' => $entry->unit_cost,
            'amount' => $entry->amount,
            'base_amount' => $entry->base_amount,
            'account_id' => $entry->account_id,
        ];
    }
}
