<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ArchiveItemQuantityUnit;
use App\Modules\Inventory\Actions\CreateItemQuantityUnit;
use App\Modules\Inventory\Actions\UpdateItemQuantityUnit;
use App\Modules\Inventory\Http\Requests\ItemQuantityUnit\IndexItemQuantityUnitRequest;
use App\Modules\Inventory\Http\Requests\ItemQuantityUnit\ShowItemQuantityUnitRequest;
use App\Modules\Inventory\Http\Requests\ItemQuantityUnit\StoreItemQuantityUnitRequest;
use App\Modules\Inventory\Http\Requests\ItemQuantityUnit\UpdateItemQuantityUnitRequest;
use App\Modules\Inventory\Http\Resources\ItemQuantityUnitResource;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR 0050's per-(item, UoM) pack conversion override — see
 * docs/domain/units-of-measure.md#item-specific-conversions. item_id/uom_id
 * are create-only; repointing an override is delete-and-recreate.
 */
class ItemQuantityUnitController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['item' => 'item', 'uom' => 'uom'];

    public function index(IndexItemQuantityUnitRequest $request): AnonymousResourceCollection
    {
        $query = ItemQuantityUnit::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.item_id')) {
            $query->where('item_id', $request->input('filter.item_id'));
        }

        $this->applySort($query, $request, ['created_at' => 'created_at'], '-created_at');

        return ItemQuantityUnitResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreItemQuantityUnitRequest $request, CreateItemQuantityUnit $create): JsonResponse
    {
        return (new ItemQuantityUnitResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowItemQuantityUnitRequest $request, ItemQuantityUnit $item_quantity_unit): ItemQuantityUnitResource
    {
        return new ItemQuantityUnitResource($item_quantity_unit->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateItemQuantityUnitRequest $request, ItemQuantityUnit $item_quantity_unit, UpdateItemQuantityUnit $update): ItemQuantityUnitResource
    {
        return new ItemQuantityUnitResource($update($item_quantity_unit, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ItemQuantityUnit $item_quantity_unit, ArchiveItemQuantityUnit $archive): Response
    {
        abort_unless($request->user()?->can('inventory.item.manage'), 403);
        $archive($item_quantity_unit, $request->user());

        return response()->noContent();
    }
}
