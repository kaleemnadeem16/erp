<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\RecordGoodsReceiptLineCompositionRequest;
use App\Modules\Purchasing\Http\Resources\GoodsReceiptLineResource;
use App\Modules\Purchasing\Models\GoodsReceiptLine;

class GoodsReceiptLineCompositionController extends Controller
{
    /** Replaces the observed composition of a draft receipt line. */
    public function update(RecordGoodsReceiptLineCompositionRequest $request, GoodsReceiptLine $goodsReceiptLine, RecordReceiptComposition $record): GoodsReceiptLineResource
    {
        $composition = array_map(function (array $row): array {
            return array_filter([
                'packaging_id' => $row['packaging_id'] ?? null,
                'count' => $row['count'] ?? null,
                'quantity' => isset($row['quantity']) ? (string) $row['quantity'] : null,
            ], fn ($value) => $value !== null);
        }, $request->validated('composition'));
        $record($goodsReceiptLine, $composition, $request->validated('reason'), $request->user());

        return new GoodsReceiptLineResource($goodsReceiptLine->refresh()->load(['purchaseOrderLine', 'packageObservations']));
    }
}
