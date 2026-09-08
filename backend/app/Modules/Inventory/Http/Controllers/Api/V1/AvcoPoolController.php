<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\AvcoPool\IndexAvcoPoolRequest;
use App\Modules\Inventory\Http\Requests\AvcoPool\ShowAvcoPoolRequest;
use App\Modules\Inventory\Http\Resources\AvcoPoolResource;
use App\Modules\Inventory\Models\AvcoPool;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: an item's current AVCO balance is written only by
 * App\Modules\Inventory\Actions\Movements\AvcoPoolManager (receipts, issues,
 * transfers, cutover opening state, recalculation execution) — see
 * docs/phases/phase-2-purchasing-stock.md#close-out. FIFO/SPECIFIC receipt
 * layers live on StockLayer instead; see StockLayerController.
 */
class AvcoPoolController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['item' => 'item', 'warehouse' => 'warehouse', 'batch' => 'batch'];

    /** @var array<string, string> */
    private const SORTABLE = ['updated_at' => 'updated_at'];

    public function index(IndexAvcoPoolRequest $request): AnonymousResourceCollection
    {
        $query = AvcoPool::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.item_id')) {
            $query->where('item_id', $request->integer('filter.item_id'));
        }
        if ($request->filled('filter.warehouse_id')) {
            $query->where('warehouse_id', $request->integer('filter.warehouse_id'));
        }
        if ($request->filled('filter.batch_id')) {
            $query->where('batch_id', $request->integer('filter.batch_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $this->applySort($query, $request, self::SORTABLE, '-updated_at');

        return AvcoPoolResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(ShowAvcoPoolRequest $request, AvcoPool $avcoPool): AvcoPoolResource
    {
        return new AvcoPoolResource($avcoPool->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }
}
