<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StockMove\IndexStockMoveRequest;
use App\Modules\Inventory\Http\Requests\StockMove\ShowStockMoveRequest;
use App\Modules\Inventory\Http\Resources\StockMoveResource;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\StockVisibility;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: App\Modules\Inventory\Actions\PostInventoryMovement is the sole
 * write path onto stock_moves and stays that way — see
 * docs/phases/phase-2-purchasing-stock.md#close-out.
 */
class StockMoveController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'item' => 'item',
        'from_location' => 'fromLocation.warehouse',
        'to_location' => 'toLocation.warehouse',
        'batch' => 'batch',
        'valuation_entries' => 'valuationEntries',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'moved_at' => 'moved_at',
    ];

    public function index(IndexStockMoveRequest $request): AnonymousResourceCollection
    {
        $query = StockMove::query();
        // A movement is reached when either side is in a warehouse the person's units own (ADR 0089).
        WarehouseScope::limitToEitherLocation($query, $request->user(), StockVisibility::MOVEMENT);

        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.item_id')) {
            $query->where('item_id', $request->integer('filter.item_id'));
        }

        if ($request->filled('filter.location_id')) {
            $locationId = $request->integer('filter.location_id');
            $query->where(fn (Builder $q) => $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId));
        }

        if ($request->filled('filter.warehouse_id')) {
            $warehouseId = $request->integer('filter.warehouse_id');
            $query->where(fn (Builder $q) => $q
                ->whereHas('fromLocation', fn (Builder $l) => $l->where('warehouse_id', $warehouseId))
                ->orWhereHas('toLocation', fn (Builder $l) => $l->where('warehouse_id', $warehouseId)));
        }

        if ($request->filled('filter.batch_id')) {
            $query->where('batch_id', $request->integer('filter.batch_id'));
        }

        if ($request->filled('filter.source_type')) {
            $query->where('source_type', $request->string('filter.source_type')->toString());
        }

        if ($request->filled('filter.moved_from')) {
            $query->where('moved_at', '>=', $request->date('filter.moved_from'));
        }

        if ($request->filled('filter.moved_to')) {
            $query->where('moved_at', '<=', $request->date('filter.moved_to'));
        }

        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->whereHas('item', fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%"));
        }

        $this->applySort($query, $request, self::SORTABLE, '-moved_at');

        $perPage = $request->integer('per_page', 25);

        return StockMoveResource::collection($query->cursorPaginate($perPage));
    }

    public function show(ShowStockMoveRequest $request, StockMove $stockMove): StockMoveResource
    {
        abort_unless(
            WarehouseScope::permitsLocation($request->user(), StockVisibility::MOVEMENT, $stockMove->from_location_id)
            || WarehouseScope::permitsLocation($request->user(), StockVisibility::MOVEMENT, $stockMove->to_location_id),
            404,
        );

        return new StockMoveResource($stockMove->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }
}
