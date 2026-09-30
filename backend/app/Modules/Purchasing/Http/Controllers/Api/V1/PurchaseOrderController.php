<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CommitInventoryCutoverOpenPurchaseOrders;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\ShortClosePurchaseOrderLine;
use App\Modules\Purchasing\Actions\SubmitPurchaseOrder;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\CancelPurchaseOrderRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\IndexPurchaseOrderRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\ShowPurchaseOrderRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderLineResource;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['lines' => 'lines', 'supplierSite' => 'supplierSite'];

    private const SORTABLE = ['number' => 'number', 'order_date' => 'order_date', 'expected_delivery_date' => 'expected_delivery_date', 'created_at' => 'created_at'];

    public function index(IndexPurchaseOrderRequest $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if (in_array('lines', $this->requestedIncludes($request, self::INCLUDABLE), true)) {
            $query->with(['lines' => fn ($lines) => $lines->withReceiptProgress()]);
        }
        foreach (['supplier_account_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->filled('filter.search')) {
            $query->where('number', 'ilike', '%'.$request->string('filter.search')->toString().'%');
        }
        $sort = $request->string('sort', 'created_at')->toString();
        if (ltrim($sort, '-') === 'total_amount') {
            // Not a real column — line_amount only exists per line — so this can't
            // go through applySort()'s plain orderBy(). The subquery is safe without
            // its own company_id filter: it's correlated to purchase_orders.id, which
            // is already company-scoped by the outer query's BelongsToCompany global
            // scope, and a line can never belong to a different company than its own
            // order (see docs/architecture/database.md "no orphaned cross-tenant rows").
            $query->orderByRaw(
                '(SELECT COALESCE(SUM(line_amount), 0) FROM purchase_order_lines WHERE purchase_order_lines.purchase_order_id = purchase_orders.id) '
                .(str_starts_with($sort, '-') ? 'DESC' : 'ASC')
            )->orderBy('id');
        } else {
            $this->applySort($query, $request, self::SORTABLE, 'created_at');
        }

        return PurchaseOrderResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrder $create): JsonResponse
    {
        return (new PurchaseOrderResource($create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $includes = $this->requestedIncludes($request, self::INCLUDABLE);
        if (in_array('lines', $includes, true)) {
            $purchaseOrder->load(['lines' => fn ($query) => $query->withReceiptProgress()]);
        }
        if (in_array('supplierSite', $includes, true)) {
            $purchaseOrder->load('supplierSite.partySite');
        }

        return new PurchaseOrderResource($purchaseOrder);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrder $update): PurchaseOrderResource
    {
        return new PurchaseOrderResource($update($purchaseOrder, $request->validated()));
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder, IssuePurchaseOrder $issue): PurchaseOrderResource
    {
        abort_unless($request->user()?->can('purchasing.purchase-order.issue'), 403);

        return new PurchaseOrderResource($issue($purchaseOrder, $request->user()));
    }

    /** Sends a draft for approval. When the company's approval mode says none is needed the order is returned unchanged. */
    public function submit(Request $request, PurchaseOrder $purchaseOrder, SubmitPurchaseOrder $submit): PurchaseOrderResource
    {
        abort_unless($request->user()?->can('purchasing.purchase-order.submit'), 403);
        $submit($purchaseOrder, $request->user());

        return new PurchaseOrderResource($purchaseOrder->refresh()->load('lines'));
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $cancel): PurchaseOrderResource
    {
        return new PurchaseOrderResource($cancel($purchaseOrder, $request->string('reason')->toString(), $request->user()));
    }

    public function shortClose(Request $request, PurchaseOrderLine $purchaseOrderLine, ShortClosePurchaseOrderLine $shortClose): PurchaseOrderLineResource
    {
        abort_unless($request->user()?->can('purchasing.purchase-order.short-close'), 403);
        $line = $shortClose($purchaseOrderLine, $request->user());

        return new PurchaseOrderLineResource(PurchaseOrderLine::query()->withReceiptProgress()->findOrFail($line->id));
    }

    public function commitCutover(Request $request, InventoryCutover $inventoryCutover, CommitInventoryCutoverOpenPurchaseOrders $commit): AnonymousResourceCollection
    {
        return PurchaseOrderResource::collection($commit($inventoryCutover, $request->user()));
    }
}
