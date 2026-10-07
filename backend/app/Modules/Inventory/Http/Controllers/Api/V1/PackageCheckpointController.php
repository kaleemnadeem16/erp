<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Http\Requests\PackageStock\StorePackageCheckpointRequest;
use App\Modules\Inventory\Models\StockLayer;
use Illuminate\Http\JsonResponse;

class PackageCheckpointController extends Controller
{
    /** Records what is physically on the shelf for one scope. The server snapshots the current layers; a ledger quantity that no longer matches is refused as stale. */
    public function store(StorePackageCheckpointRequest $request, RecordPackageCheckpoint $record): JsonResponse
    {
        $input = $request->validated();
        $layers = StockLayer::query()->where(['item_id' => $input['item_id'], 'location_id' => $input['location_id'], 'batch_id' => $input['batch_id'] ?? null, 'serial_no' => null])
            ->where('qty_remaining', '>', 0)->orderBy('id')->pluck('qty_remaining', 'id')->all();
        $composition = array_map(fn (array $row): array => array_filter([
            'packaging_id' => $row['packaging_id'] ?? null, 'count' => $row['count'] ?? null, 'quantity' => isset($row['quantity']) ? (string) $row['quantity'] : null,
        ], fn ($value) => $value !== null), $input['composition']);
        $operation = $record([
            'item_id' => $input['item_id'], 'location_id' => $input['location_id'], 'batch_id' => $input['batch_id'] ?? null, 'request_key' => $input['request_key'],
            'reason' => $input['reason'], 'expected_quantity' => (string) $input['expected_quantity'], 'expected_layers' => $layers, 'composition' => $composition,
        ], $request->user());

        return response()->json(['data' => ['operation_id' => $operation->id, 'evidence_kind' => $operation->evidence_kind]], 201);
    }
}
