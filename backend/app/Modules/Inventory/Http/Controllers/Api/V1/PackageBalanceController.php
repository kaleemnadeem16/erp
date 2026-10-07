<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Http\Requests\PackageStock\ShowPackageBalancesRequest;
use Illuminate\Http\JsonResponse;

class PackageBalanceController extends Controller
{
    /** The physical breakdown (sealed packages and loose quantity) of an item's stock per location and batch, with its freshness. */
    public function index(ShowPackageBalancesRequest $request, ReadPackageBalances $read): JsonResponse
    {
        return response()->json(['data' => $read($request->integer('item_id'), $request->filled('location_id') ? $request->integer('location_id') : null,
            $request->filled('batch_id') ? $request->integer('batch_id') : null, $request->user())]);
    }
}
