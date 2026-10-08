<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\PreviewRejectedStockExit;
use App\Modules\Inventory\Http\Requests\PackageStock\ShowRejectedStockRequest;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Http\JsonResponse;

class RejectedStockController extends Controller
{
    /** Rejected quantity still on hand for one delivery line, the package draw it would need, cost basis and blockers; posts nothing. */
    public function show(ShowRejectedStockRequest $request, GoodsReceiptLine $goodsReceiptLine, PreviewRejectedStockExit $preview): JsonResponse
    {
        $kind = $request->string('kind')->toString();
        $capabilities = $kind === RejectedStockExit::SUPPLIER_RETURN ? ['purchasing.supplier-return.create', 'purchasing.supplier-return.post'] : ['inventory.scrap.create', 'inventory.scrap.post'];
        $line = ['goods_receipt_line_id' => $goodsReceiptLine->id, 'quantity' => $request->input('quantity')];

        return response()->json(['data' => $preview->line($kind, $line, $request->user(), $capabilities)]);
    }
}
