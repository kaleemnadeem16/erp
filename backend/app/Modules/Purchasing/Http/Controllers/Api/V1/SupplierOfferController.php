<?php

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\MaintainSupplierOffer;
use App\Modules\Purchasing\Http\Requests\SupplierOffer\IndexSupplierOfferRequest;
use App\Modules\Purchasing\Http\Requests\SupplierOffer\StoreSupplierOfferRequest;
use App\Modules\Purchasing\Http\Requests\SupplierOffer\UpdateSupplierOfferRequest;
use App\Modules\Purchasing\Http\Resources\SupplierOfferResource;
use App\Modules\Purchasing\Models\SupplierOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierOfferController extends Controller
{
    public function index(IndexSupplierOfferRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('purchasing.item-supplier.view') || $request->user()?->can('purchasing.item-supplier.manage') || $request->user()?->can('purchasing.purchase-order.create'), 403);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $q = SupplierOffer::query();
        if ($request->filled('filter.item_id')) {
            $q->whereHas('relationship', fn ($r) => $r->where('item_id', $request->input('filter.item_id')));
        }
        if ($request->filled('filter.supplier_account_id')) {
            $q->whereHas('relationship', fn ($r) => $r->where('supplier_account_id', $request->input('filter.supplier_account_id')));
        }
        if ($request->filled('filter.active')) {
            $q->where('active', $request->boolean('filter.active'));
        }

        return SupplierOfferResource::collection($q->orderBy('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreSupplierOfferRequest $request, MaintainSupplierOffer $maintain): JsonResponse
    {
        return (new SupplierOfferResource($maintain($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierOfferRequest $request, SupplierOffer $supplier_offer, MaintainSupplierOffer $maintain): SupplierOfferResource
    {
        return new SupplierOfferResource($maintain($request->validated(), $request->user(), $supplier_offer));
    }
}
