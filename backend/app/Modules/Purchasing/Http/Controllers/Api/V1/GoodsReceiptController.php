<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PreviewGoodsReceiptReversal;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Actions\UpdateGoodsReceipt;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\IndexGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\PreviewGoodsReceiptReversalRequest;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\ReverseGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\StoreGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\UpdateGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Resources\GoodsReceiptResource;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['lines' => 'lines', 'supplierAccount' => 'supplierAccount'];

    private const SORTABLE = [
        'number' => 'number',
        'posting_date' => 'posting_date',
        'physical_arrival_at' => 'physical_arrival_at',
        'recorded_at' => 'recorded_at',
        'posted_at' => 'posted_at',
        'status' => 'status',
        'total_amount' => 'total_amount',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function index(IndexGoodsReceiptRequest $request): AnonymousResourceCollection
    {
        $query = GoodsReceipt::query()
            ->select('goods_receipts.*')
            ->withCount('lines')
            ->selectRaw(
                '(SELECT COALESCE(SUM(COALESCE(provisional_amount, received_qty * provisional_unit_cost)), 0) FROM goods_receipt_lines WHERE goods_receipt_lines.goods_receipt_id = goods_receipts.id) as total_amount'
            )
            ->selectRaw(
                '(SELECT currency_id FROM goods_receipt_lines WHERE goods_receipt_lines.goods_receipt_id = goods_receipts.id LIMIT 1) as currency_id'
            );
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['supplier_account_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->filled('filter.posting_date_from')) {
            $query->where('posting_date', '>=', $request->input('filter.posting_date_from'));
        }
        if ($request->filled('filter.posting_date_to')) {
            $query->where('posting_date', '<=', $request->input('filter.posting_date_to'));
        }
        if ($request->filled('filter.physical_arrival_from')) {
            $query->where('physical_arrival_at', '>=', $request->input('filter.physical_arrival_from'));
        }
        if ($request->filled('filter.physical_arrival_to')) {
            $query->where('physical_arrival_at', '<=', $request->input('filter.physical_arrival_to'));
        }
        if ($request->filled('filter.total_min')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(COALESCE(provisional_amount, received_qty * provisional_unit_cost)), 0) FROM goods_receipt_lines WHERE goods_receipt_lines.goods_receipt_id = goods_receipts.id) >= ?',
                [$request->input('filter.total_min')]
            );
        }
        if ($request->filled('filter.total_max')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(COALESCE(provisional_amount, received_qty * provisional_unit_cost)), 0) FROM goods_receipt_lines WHERE goods_receipt_lines.goods_receipt_id = goods_receipts.id) <= ?',
                [$request->input('filter.total_max')]
            );
        }
        if ($request->filled('filter.search')) {
            $term = $request->string('filter.search')->toString();
            $query->where(function ($q) use ($term): void {
                $q->where('number', 'ilike', '%'.$term.'%')
                    ->orWhereHas('supplierAccount', function ($sq) use ($term): void {
                        $sq->where('code', 'ilike', '%'.$term.'%')
                            ->orWhereHas('party', function ($pq) use ($term): void {
                                $pq->where('name', 'ilike', '%'.$term.'%');
                            });
                    });
            });
        }
        $sort = $request->string('sort', 'created_at')->toString();
        if (ltrim($sort, '-') === 'total_amount') {
            $query->orderByRaw(
                '(SELECT COALESCE(SUM(COALESCE(provisional_amount, received_qty * provisional_unit_cost)), 0) FROM goods_receipt_lines WHERE goods_receipt_lines.goods_receipt_id = goods_receipts.id) '
                .(str_starts_with($sort, '-') ? 'DESC' : 'ASC')
            )->orderBy('id');
        } else {
            $this->applySort($query, $request, self::SORTABLE, 'created_at');
        }

        return GoodsReceiptResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreGoodsReceiptRequest $request, CreateGoodsReceipt $create): JsonResponse
    {
        $receipt = $create($request->validated(), $request->user(), $request->header('Idempotency-Key'));
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations'), 'supplierAccount.party']);

        return (new GoodsReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, UpdateGoodsReceipt $update): GoodsReceiptResource
    {
        $receipt = $update($goodsReceipt, $request->validated(), $request->user());

        return new GoodsReceiptResource($receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations'), 'supplierAccount.party']));
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        abort_unless(PurchasingReadAccess::goodsReceipts($request->user()), 403);

        return new GoodsReceiptResource($goodsReceipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations'), 'supplierAccount.party']));
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt, PostGoodsReceipt $post): GoodsReceiptResource
    {
        abort_unless($request->user()?->can('purchasing.goods-receipt.post'), 403);
        $receipt = $post($goodsReceipt, $request->user());
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]);

        return new GoodsReceiptResource($receipt);
    }

    public function reverse(ReverseGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, ReverseGoodsReceipt $reverse): GoodsReceiptResource
    {
        // Per receipt line, the sealed packages and loose quantity physically going back (grouped items only; omitted means what the receipt's evidence says arrived).
        $draws = [];
        foreach ($request->validated('package_draws') ?? [] as $entry) {
            $draws[(int) $entry['goods_receipt_line_id']] = array_map(fn (array $row): array => array_filter($row, fn ($value): bool => $value !== null), $entry['lines']);
        }
        $receipt = $reverse($goodsReceipt, $request->string('reason')->toString(), $request->user()->id, $draws);
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]);

        return new GoodsReceiptResource($receipt);
    }

    /** What reversing this receipt would do, per line, and what stands in the way; posts nothing. */
    public function reversalPreview(PreviewGoodsReceiptReversalRequest $request, GoodsReceipt $goodsReceipt, PreviewGoodsReceiptReversal $preview): JsonResponse
    {
        return response()->json(['data' => $preview($goodsReceipt, $request->user())]);
    }
}
