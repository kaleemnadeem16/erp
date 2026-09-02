<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\ReverseGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Requests\GoodsReceipt\StoreGoodsReceiptRequest;
use App\Modules\Purchasing\Http\Resources\GoodsReceiptResource;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function store(StoreGoodsReceiptRequest $request, CreateGoodsReceipt $create): JsonResponse
    {
        $receipt = $create($request->validated(), $request->header('Idempotency-Key'));
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()]);

        return (new GoodsReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        return new GoodsReceiptResource($goodsReceipt->load(['lines' => fn ($query) => $query->withQaAggregates()]));
    }

    public function post(GoodsReceipt $goodsReceipt, PostGoodsReceipt $post): GoodsReceiptResource
    {
        $receipt = $post($goodsReceipt);
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()]);

        return new GoodsReceiptResource($receipt);
    }

    public function reverse(ReverseGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, ReverseGoodsReceipt $reverse): GoodsReceiptResource
    {
        $receipt = $reverse($goodsReceipt, $request->string('reason')->toString(), $request->user()->id);
        $receipt->load(['lines' => fn ($query) => $query->withQaAggregates()]);

        return new GoodsReceiptResource($receipt);
    }
}
