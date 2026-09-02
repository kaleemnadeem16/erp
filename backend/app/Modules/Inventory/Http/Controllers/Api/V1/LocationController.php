<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ArchiveLocation;
use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\UpdateLocation;
use App\Modules\Inventory\Http\Requests\Location\IndexLocationRequest;
use App\Modules\Inventory\Http\Requests\Location\ShowLocationRequest;
use App\Modules\Inventory\Http\Requests\Location\StoreLocationRequest;
use App\Modules\Inventory\Http\Requests\Location\UpdateLocationRequest;
use App\Modules\Inventory\Http\Resources\LocationResource;
use App\Modules\Inventory\Models\Location;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class LocationController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['warehouse' => 'warehouse'];

    private const SORTABLE = ['code' => 'code', 'name' => 'name', 'created_at' => 'created_at'];

    public function index(IndexLocationRequest $request): AnonymousResourceCollection
    {
        $query = Location::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['warehouse_id', 'active'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }$this->applySort($query, $request, self::SORTABLE, 'name');

        return LocationResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreLocationRequest $request, CreateLocation $create): JsonResponse
    {
        return (new LocationResource($create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowLocationRequest $request, Location $location): LocationResource
    {
        return new LocationResource($location->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateLocationRequest $request, Location $location, UpdateLocation $update): LocationResource
    {
        return new LocationResource($update($location, $request->validated()));
    }

    public function destroy(Location $location, ArchiveLocation $archive): Response
    {
        $archive($location);

        return response()->noContent();
    }
}
