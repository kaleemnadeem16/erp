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
use App\Modules\Purchasing\Support\PurchaseOrderApprovalState;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['lines' => 'lines', 'supplierSite' => 'supplierSite'];

    private const SORTABLE = ['number' => 'number', 'order_date' => 'order_date', 'expected_delivery_date' => 'expected_delivery_date', 'created_at' => 'created_at'];

    public function previewLine(\App\Modules\Purchasing\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest $request, \App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes $build): \App\Modules\Purchasing\Http\Resources\PurchaseOrderLineResource
    {
        $input = $request->validated();
        $lines = \Illuminate\Support\Arr::pull($input, 'lines');
        $order = new \App\Modules\Purchasing\Models\PurchaseOrder($input);
        $line = new \App\Modules\Purchasing\Models\PurchaseOrderLine($build($order, $lines[0], true));
        return new \App\Modules\Purchasing\Http\Resources\PurchaseOrderLineResource($line);
    }

    public function index(IndexPurchaseOrderRequest $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if (in_array('lines', $this->requestedIncludes($request, self::INCLUDABLE), true)) {
            $query->with(['lines' => fn ($lines) => $lines->withReceiptProgress()]);
        }
        foreach (['supplier_account_id', 'supplier_site_id', 'status', 'currency_id'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->filled('filter.order_date_from')) {
            $query->where('order_date', '>=', $request->date('filter.order_date_from')->toDateString());
        }
        if ($request->filled('filter.order_date_to')) {
            $query->where('order_date', '<=', $request->date('filter.order_date_to')->toDateString());
        }
        if ($request->filled('filter.expected_delivery_from')) {
            $query->where('expected_delivery_date', '>=', $request->date('filter.expected_delivery_from')->toDateString());
        }
        if ($request->filled('filter.expected_delivery_to')) {
            $query->where('expected_delivery_date', '<=', $request->date('filter.expected_delivery_to')->toDateString());
        }
        if ($request->filled('filter.issued_from')) {
            $query->where('issued_at', '>=', $request->date('filter.issued_from'));
        }
        if ($request->filled('filter.issued_to')) {
            $query->where('issued_at', '<=', $request->date('filter.issued_to')->endOfDay());
        }
        if ($request->filled('filter.total_min')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(line_amount), 0) FROM purchase_order_lines WHERE purchase_order_lines.purchase_order_id = purchase_orders.id) >= ?',
                [$request->input('filter.total_min')]
            );
        }
        if ($request->filled('filter.total_max')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(line_amount), 0) FROM purchase_order_lines WHERE purchase_order_lines.purchase_order_id = purchase_orders.id) <= ?',
                [$request->input('filter.total_max')]
            );
        }
        if ($request->filled('filter.approval')) {
            $wanted = $request->string('filter.approval')->toString();
            $ofThisOrder = function (QueryBuilder $sub): QueryBuilder {
                return $sub->selectRaw('1')->from('approval_requests as ar')
                    ->where('ar.subject_type', PurchaseOrderApprovalSubject::CODE)
                    ->whereColumn('ar.subject_id', 'purchase_orders.id')
                    ->whereColumn('ar.company_id', 'purchase_orders.company_id');
            };
            if ($wanted === 'NONE') {
                $query->whereNotExists($ofThisOrder(...));
            } else {
                // Only the latest request counts: an order approved long ago and since re-submitted is judged by the re-submission.
                $query->whereExists(fn (QueryBuilder $sub) => $ofThisOrder($sub)
                    ->where('ar.status', $wanted)
                    ->whereRaw('ar.id = (SELECT MAX(r2.id) FROM approval_requests r2 WHERE r2.subject_type = ar.subject_type AND r2.subject_id = ar.subject_id AND r2.company_id = ar.company_id)'));
            }
        }
        if ($request->filled('filter.item_id')) {
            $query->whereHas('lines', function (Builder $line) use ($request): void {
                $line->where('item_id', $request->integer('filter.item_id'));
            });
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $term = '%'.$search.'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('number', 'ilike', $term)
                    ->orWhere('source_record_id', 'ilike', $term)
                    ->orWhereHas('supplierAccount', function (Builder $sq) use ($term): void {
                        $sq->where('code', 'ilike', $term)
                            ->orWhereHas('party', fn (Builder $pq) => $pq->where('name', 'ilike', $term));
                    })
                    ->orWhereHas('lines', function (Builder $lq) use ($term): void {
                        $lq->where('item_code', 'ilike', $term)
                            ->orWhere('description', 'ilike', $term)
                            ->orWhere('supplier_item_code', 'ilike', $term);
                    });
            });
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

        $page = $query->paginate($request->integer('per_page', 25));
        // The list's Approval column: one query for the page's latest requests, not one per row.
        $statuses = app(PurchaseOrderApprovalState::class)->statusesFor($page->getCollection());
        $page->getCollection()->each(fn (PurchaseOrder $order) => $order->setAttribute('approval_status', $statuses[$order->id] ?? null));

        return PurchaseOrderResource::collection($page);
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
