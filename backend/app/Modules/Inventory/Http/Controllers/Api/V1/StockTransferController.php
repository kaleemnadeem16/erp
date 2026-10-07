<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\TransferGroupedStock;
use App\Modules\Inventory\Http\Requests\PackageStock\StoreStockTransferRequest;
use Illuminate\Http\JsonResponse;

class StockTransferController extends Controller
{
    /** Transfers stock of a package-tracked item by what physically moves. Idempotent on request_key. */
    public function store(StoreStockTransferRequest $request, TransferGroupedStock $transfer): JsonResponse
    {
        $result = $transfer($request->safe()->only(['item_id', 'from_location_id', 'to_location_id', 'batch_id', 'request_key', 'reason', 'reference', 'quantity', 'draw']), $request->user());

        return response()->json(['data' => [
            'move_id' => $result['move']->id, 'quantity' => $result['move']->qty, 'source_operation_id' => $result['source']->id,
            'destination_operation_id' => $result['destination']->id, 'source_balance' => $result['source_balance'], 'destination_balance' => $result['destination_balance'],
        ]], 201);
    }
}
