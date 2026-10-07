<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Http\Requests\ItemPackaging\IndexItemPackagingRequest;
use App\Modules\Inventory\Http\Requests\ItemPackaging\StoreItemPackagingRequest;
use App\Modules\Inventory\Http\Requests\ItemPackaging\UpdateItemPackagingRequest;
use App\Modules\Inventory\Http\Resources\ItemPackagingResource;
use App\Modules\Inventory\Models\ItemPackaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemPackagingController extends Controller
{
    public function index(IndexItemPackagingRequest $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $q = ItemPackaging::query();
        if ($request->filled('filter.item_id')) {
            $q->where('item_id', $request->input('filter.item_id'));
        }
        if ($request->filled('filter.search')) {
            $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->input('filter.search').'%')->orWhere('code', 'ilike', '%'.$request->input('filter.search').'%'));
        }
        if ($request->filled('filter.active')) {
            $q->where('active', $request->boolean('filter.active'));
        }

        return ItemPackagingResource::collection($q->orderBy('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreItemPackagingRequest $request, MaintainItemPackaging $maintain): JsonResponse
    {
        return (new ItemPackagingResource($maintain($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateItemPackagingRequest $request, ItemPackaging $item_packaging, MaintainItemPackaging $maintain): ItemPackagingResource
    {
        return new ItemPackagingResource($maintain($request->validated(), $request->user(), $item_packaging));
    }
}
