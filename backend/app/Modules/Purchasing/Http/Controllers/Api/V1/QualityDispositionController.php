<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Http\Requests\QualityDisposition\IndexQualityDispositionRequest;
use App\Modules\Purchasing\Http\Requests\QualityDisposition\StoreQualityDispositionRequest;
use App\Modules\Purchasing\Http\Resources\QualityDispositionResource;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QualityDispositionController extends Controller
{
    use AppliesApiQuery;

    private const SORTABLE = ['disposed_at' => 'disposed_at', 'qty' => 'qty'];

    public function store(StoreQualityDispositionRequest $request, GoodsReceiptLine $goodsReceiptLine, PostQualityDisposition $post): JsonResponse
    {
        $disposition = $post($goodsReceiptLine, $request->validated(), $request->user()->id, $request->header('Idempotency-Key'));
        $disposition->loadSum('childDispositions as children_qty_sum', 'qty');

        return (new QualityDispositionResource($disposition))->response()->setStatusCode(201);
    }

    public function index(IndexQualityDispositionRequest $request, GoodsReceiptLine $goodsReceiptLine): AnonymousResourceCollection
    {
        // Relation::__call() returns $this (the relation), not the underlying
        // builder, when a forwarded method like withSum() returns the query
        // unchanged — so applySort()'s Builder type hint requires starting from a
        // plain query instead of chaining onto $goodsReceiptLine->qualityDispositions().
        $query = QualityDisposition::query()->where('goods_receipt_line_id', $goodsReceiptLine->id)
            ->withSum('childDispositions as children_qty_sum', 'qty');
        if ($request->filled('filter.disposition')) {
            $query->where('disposition', $request->input('filter.disposition'));
        }
        $this->applySort($query, $request, self::SORTABLE, 'disposed_at');

        return QualityDispositionResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(QualityDisposition $qualityDisposition): QualityDispositionResource
    {
        $qualityDisposition->loadSum('childDispositions as children_qty_sum', 'qty');

        return new QualityDispositionResource($qualityDisposition);
    }
}
