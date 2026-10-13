<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\PreviewRejectedStockExit;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Actions\CancelSupplierReturn;
use App\Modules\Purchasing\Actions\CreateSupplierReturn;
use App\Modules\Purchasing\Actions\PostSupplierReturn;
use App\Modules\Purchasing\Actions\UpdateSupplierReturn;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\CancelSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\IndexSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\PostSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\PreviewSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\ShowSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\StoreSupplierReturnRequest;
use App\Modules\Purchasing\Http\Requests\SupplierReturn\UpdateSupplierReturnRequest;
use App\Modules\Purchasing\Http\Resources\SupplierReturnResource;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Support\SupplierReturnReadAccess;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The supplier return (ADR 0097): draft → post over the shared rejected-stock exit core. Creating or editing a draft moves nothing; posting is the one atomic point. Record
 * scope: a person limited to their units sees and acts on documents of their own warehouses only (404 elsewhere).
 */
class SupplierReturnController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['warehouse' => 'warehouse', 'lines' => 'lines'];

    /** @var array<string, string> */
    private const SORTABLE = ['created_at' => 'created_at', 'posted_at' => 'posted_at', 'number' => 'number'];

    public function index(IndexSupplierReturnRequest $request): AnonymousResourceCollection
    {
        $query = SupplierReturn::query();
        WarehouseScope::limitToWarehouse($query, $request->user(), SupplierReturnReadAccess::PERMISSIONS);
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.warehouse_id')) {
            $query->where('warehouse_id', $request->integer('filter.warehouse_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.search')) {
            $search = '%'.$request->string('filter.search')->toString().'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $search)->orWhere('supplier_reference', 'ilike', $search));
        }
        if ($request->filled('filter.supplier_account_id')) {
            $query->where('supplier_account_id', $request->integer('filter.supplier_account_id'));
        }
        if ($request->filled('filter.created_from')) {
            $query->whereDate('created_at', '>=', $request->input('filter.created_from'));
        }
        if ($request->filled('filter.created_to')) {
            $query->whereDate('created_at', '<=', $request->input('filter.created_to'));
        }
        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return SupplierReturnResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(ShowSupplierReturnRequest $request, SupplierReturn $supplierReturn): SupplierReturnResource
    {
        $this->inScope($request, $supplierReturn);

        return new SupplierReturnResource($supplierReturn->load('lines'));
    }

    public function store(StoreSupplierReturnRequest $request, CreateSupplierReturn $create): JsonResponse
    {
        return (new SupplierReturnResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierReturnRequest $request, SupplierReturn $supplierReturn, UpdateSupplierReturn $update): SupplierReturnResource
    {
        $this->inScope($request, $supplierReturn);

        return new SupplierReturnResource($update($supplierReturn, $request->validated(), $request->user()));
    }

    public function post(PostSupplierReturnRequest $request, SupplierReturn $supplierReturn, PostSupplierReturn $post): SupplierReturnResource
    {
        $this->inScope($request, $supplierReturn);

        return new SupplierReturnResource($post($supplierReturn, $request->user()));
    }

    public function cancel(CancelSupplierReturnRequest $request, SupplierReturn $supplierReturn, CancelSupplierReturn $cancel): SupplierReturnResource
    {
        $this->inScope($request, $supplierReturn);

        return new SupplierReturnResource($cancel($supplierReturn, $request->user()));
    }

    /** What posting these lines would do — rejected quantity remaining, package draw, cost basis and every blocker; nothing is posted or saved. */
    public function preview(PreviewSupplierReturnRequest $request, PreviewRejectedStockExit $preview): JsonResponse
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $request->validated('lines');

        return response()->json(['data' => $preview(RejectedStockExit::SUPPLIER_RETURN, $lines, $request->user())]);
    }

    private function inScope(Request $request, SupplierReturn $supplierReturn): void
    {
        abort_unless(WarehouseScope::permitsWarehouse($request->user(), SupplierReturnReadAccess::PERMISSIONS, (int) $supplierReturn->warehouse_id), 404);
    }
}
