<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StockLayer\IndexStockLayerRequest;
use App\Modules\Inventory\Http\Requests\StockLayer\ShowStockLayerRequest;
use App\Modules\Inventory\Http\Resources\StockLayerResource;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\StockVisibility;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: FIFO/SPECIFIC receipt layers are written only by
 * App\Modules\Inventory\Actions\PostInventoryMovement and the recalculation
 * executors (App\Modules\Inventory\Actions\Recalculation\*) — see
 * docs/phases/phase-2-purchasing-stock.md#close-out. An item's current AVCO
 * balance lives on AvcoPool instead; see AvcoPoolController.
 */
class StockLayerController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['item' => 'item', 'location' => 'location', 'batch' => 'batch'];

    /** @var array<string, string> */
    private const SORTABLE = ['received_at' => 'received_at'];

    public function index(IndexStockLayerRequest $request): AnonymousResourceCollection
    {
        $query = StockLayer::query();
        // Only the warehouses the person's units own (ADR 0089); the same WHERE serves filter, search, sort, include and the paging.
        WarehouseScope::limitToLocation($query, $request->user(), StockVisibility::STOCK);
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.item_id')) {
            $query->where('item_id', $request->integer('filter.item_id'));
        }
        if ($request->filled('filter.location_id')) {
            $query->where('location_id', $request->integer('filter.location_id'));
        }
        if ($request->filled('filter.batch_id')) {
            $query->where('batch_id', $request->integer('filter.batch_id'));
        }
        if ($request->filled('filter.source_type')) {
            $query->where('source_type', $request->string('filter.source_type')->toString());
        }
        if ($request->filled('filter.open')) {
            $request->boolean('filter.open') ? $query->where('qty_remaining', '>', 0) : $query->where('qty_remaining', '<=', 0);
        }

        $this->applySort($query, $request, self::SORTABLE, '-received_at');

        return StockLayerResource::collection($query->cursorPaginate($request->integer('per_page', 25)));
    }

    public function show(ShowStockLayerRequest $request, StockLayer $stockLayer): StockLayerResource
    {
        // Out of scope is a 404, so the layer's existence is not confirmed.
        abort_unless(WarehouseScope::permitsLocation($request->user(), StockVisibility::STOCK, $stockLayer->location_id), 404);

        return new StockLayerResource($stockLayer->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }
}
