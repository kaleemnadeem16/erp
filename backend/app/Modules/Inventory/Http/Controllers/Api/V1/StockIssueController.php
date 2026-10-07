<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\IssueGroupedStock;
use App\Modules\Inventory\Http\Requests\PackageStock\StoreStockIssueRequest;
use Illuminate\Http\JsonResponse;

class StockIssueController extends Controller
{
    /** Issues stock of a package-tracked item by what is physically taken. Idempotent on request_key. */
    public function store(StoreStockIssueRequest $request, IssueGroupedStock $issue): JsonResponse
    {
        $result = $issue($request->safe()->only(['item_id', 'location_id', 'batch_id', 'request_key', 'reason', 'reference', 'quantity', 'draw']), $request->user());

        return response()->json(['data' => [
            'move_id' => $result['move']->id, 'quantity' => $result['move']->qty, 'operation_id' => $result['operation']->id, 'balance' => $result['balance'],
        ]], 201);
    }
}
