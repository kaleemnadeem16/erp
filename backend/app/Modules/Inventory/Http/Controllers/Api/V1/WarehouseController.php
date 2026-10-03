<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ArchiveWarehouse;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\UpdateWarehouse;
use App\Modules\Inventory\Http\Requests\Warehouse\IndexWarehouseRequest;
use App\Modules\Inventory\Http\Requests\Warehouse\ShowWarehouseRequest;
use App\Modules\Inventory\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Modules\Inventory\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['locations' => 'locations'];

    private const SORTABLE = ['code' => 'code', 'name' => 'name', 'created_at' => 'created_at'];

    public function index(IndexWarehouseRequest $request): AnonymousResourceCollection
    {
        $query = Warehouse::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.active')) {
            $query->where('active', $request->boolean('filter.active'));
        }if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }$this->applySort($query, $request, self::SORTABLE, 'name');

        return WarehouseResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreWarehouseRequest $request, CreateWarehouse $create): JsonResponse
    {
        return (new WarehouseResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse, UpdateWarehouse $update): WarehouseResource
    {
        return new WarehouseResource($update($warehouse, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Warehouse $warehouse, ArchiveWarehouse $archive): Response
    {
        abort_unless($request->user()?->can('inventory.warehouse.manage'), 403);
        $archive($warehouse, $request->user());

        return response()->noContent();
    }
}
