<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\CancelStockScrap;
use App\Modules\Inventory\Actions\CreateStockScrap;
use App\Modules\Inventory\Actions\PostStockScrap;
use App\Modules\Inventory\Actions\PreviewRejectedStockExit;
use App\Modules\Inventory\Actions\UpdateStockScrap;
use App\Modules\Inventory\Http\Requests\StockScrap\CancelStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\IndexStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\PostStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\PreviewStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\ShowStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\StoreStockScrapRequest;
use App\Modules\Inventory\Http\Requests\StockScrap\UpdateStockScrapRequest;
use App\Modules\Inventory\Http\Resources\StockScrapResource;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Inventory\Support\StockScrapReadAccess;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The scrap document (ADR 0097): draft → post over the shared rejected-stock exit core. Creating or editing a draft moves nothing; posting is the one atomic point. Record
 * scope: a person limited to their units sees and acts on documents of their own warehouses only (404 elsewhere).
 */
class StockScrapController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['warehouse' => 'warehouse', 'lines' => 'lines'];

    /** @var array<string, string> */
    private const SORTABLE = ['created_at' => 'created_at', 'posted_at' => 'posted_at', 'number' => 'number'];

    public function index(IndexStockScrapRequest $request): AnonymousResourceCollection
    {
        $query = StockScrap::query();
        WarehouseScope::limitToWarehouse($query, $request->user(), StockScrapReadAccess::PERMISSIONS);
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.warehouse_id')) {
            $query->where('warehouse_id', $request->integer('filter.warehouse_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.search')) {
            $search = '%'.$request->string('filter.search')->toString().'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $search)->orWhere('disposal_reference', 'ilike', $search));
        }
        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return StockScrapResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(ShowStockScrapRequest $request, StockScrap $stockScrap): StockScrapResource
    {
        $this->inScope($request, $stockScrap);

        return new StockScrapResource($stockScrap->load('lines'));
    }

    public function store(StoreStockScrapRequest $request, CreateStockScrap $create): JsonResponse
    {
        return (new StockScrapResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateStockScrapRequest $request, StockScrap $stockScrap, UpdateStockScrap $update): StockScrapResource
    {
        $this->inScope($request, $stockScrap);

        return new StockScrapResource($update($stockScrap, $request->validated(), $request->user()));
    }

    public function post(PostStockScrapRequest $request, StockScrap $stockScrap, PostStockScrap $post): StockScrapResource
    {
        $this->inScope($request, $stockScrap);

        return new StockScrapResource($post($stockScrap, $request->user()));
    }

    public function cancel(CancelStockScrapRequest $request, StockScrap $stockScrap, CancelStockScrap $cancel): StockScrapResource
    {
        $this->inScope($request, $stockScrap);

        return new StockScrapResource($cancel($stockScrap, $request->user()));
    }

    /** What posting these lines would do — rejected quantity remaining, package draw, cost basis and every blocker; nothing is posted or saved. */
    public function preview(PreviewStockScrapRequest $request, PreviewRejectedStockExit $preview): JsonResponse
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $request->validated('lines');

        return response()->json(['data' => $preview(RejectedStockExit::SCRAP, $lines, $request->user())]);
    }

    private function inScope(Request $request, StockScrap $stockScrap): void
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), StockScrapReadAccess::PERMISSIONS, (int) $stockScrap->warehouse_id), 404);
    }
}
