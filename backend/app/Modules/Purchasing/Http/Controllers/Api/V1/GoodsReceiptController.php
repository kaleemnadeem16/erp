<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Actions\UpdateGoodsReceipt;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\IndexGoodsReceiptRequest;
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

    private const SORTABLE = ['number' => 'number', 'posting_date' => 'posting_date', 'physical_arrival_at' => 'physical_arrival_at', 'created_at' => 'created_at'];

    public function index(IndexGoodsReceiptRequest $request): AnonymousResourceCollection
    {
        $query = GoodsReceipt::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['supplier_account_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->filled('filter.search')) {
            $query->where('number', 'ilike', '%'.$request->string('filter.search')->toString().'%');
        }
        $this->applySort($query, $request, self::SORTABLE, 'created_at');

        return GoodsReceiptResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreGoodsReceiptRequest $request, CreateGoodsReceipt $create): JsonResponse
    {
        $receipt = $create($request->validated(), $request->user(), $request->header('Idempotency-Key'));
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]);

        return (new GoodsReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, UpdateGoodsReceipt $update): GoodsReceiptResource
    {
        $receipt = $update($goodsReceipt, $request->validated(), $request->user());

        return new GoodsReceiptResource($receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]));
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        abort_unless(PurchasingReadAccess::goodsReceipts($request->user()), 403);

        return new GoodsReceiptResource($goodsReceipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]));
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
        $receipt = $reverse($goodsReceipt, $request->string('reason')->toString(), $request->user()->id);
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()->with('packageObservations')]);

        return new GoodsReceiptResource($receipt);
    }
}
