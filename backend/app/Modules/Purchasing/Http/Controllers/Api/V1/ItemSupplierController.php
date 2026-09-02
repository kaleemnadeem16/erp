<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\ArchiveItemSupplier;
use App\Modules\Purchasing\Actions\CreateItemSupplier;
use App\Modules\Purchasing\Actions\UpdateItemSupplier;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\IndexItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\ShowItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\StoreItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\UpdateItemSupplierRequest;
use App\Modules\Purchasing\Http\Resources\ItemSupplierResource;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ItemSupplierController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['item' => 'item', 'supplierAccount' => 'supplierAccount', 'purchaseUom' => 'purchaseUom'];

    private const SORTABLE = ['priority' => 'priority', 'created_at' => 'created_at'];

    public function index(IndexItemSupplierRequest $request): AnonymousResourceCollection
    {
        $query = ItemSupplier::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['item_id', 'supplier_account_id', 'active'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->boolean('filter.preferred')) {
            $query->where('priority', 1)->where('active', true);
        }
        $this->applySort($query, $request, self::SORTABLE, 'priority');

        return ItemSupplierResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreItemSupplierRequest $request, CreateItemSupplier $create): JsonResponse
    {
        return (new ItemSupplierResource($create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowItemSupplierRequest $request, ItemSupplier $item_supplier): ItemSupplierResource
    {
        return new ItemSupplierResource($item_supplier->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateItemSupplierRequest $request, ItemSupplier $item_supplier, UpdateItemSupplier $update): ItemSupplierResource
    {
        return new ItemSupplierResource($update($item_supplier, $request->validated()));
    }

    public function destroy(ItemSupplier $item_supplier, ArchiveItemSupplier $archive): Response
    {
        $archive($item_supplier);

        return response()->noContent();
    }
}
