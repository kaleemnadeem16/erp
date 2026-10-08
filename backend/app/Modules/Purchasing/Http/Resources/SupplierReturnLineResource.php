<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\SupplierReturnLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierReturnLine */
class SupplierReturnLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SupplierReturnLine $line */
        $line = $this->resource;

        return [
            'id' => $line->id, 'line_no' => $line->line_no, 'goods_receipt_line_id' => $line->goods_receipt_line_id, 'item_id' => $line->item_id, 'location_id' => $line->location_id,
            'quantity' => $line->quantity, 'outcome_change_reason' => $line->outcome_change_reason, 'package_portion' => $line->package_portion,
            'rejected_stock_exit_id' => $line->rejected_stock_exit_id,
        ];
    }
}
