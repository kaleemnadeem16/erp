<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\CreateWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\DeleteWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\UpdateWarehouseLocationAssignment;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\IndexWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\ShowWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\StoreWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\UpdateWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Resources\WarehouseLocationAssignmentResource;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class WarehouseLocationAssignmentController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['warehouse' => 'warehouse', 'location' => 'location'];

    private const SORTABLE = ['role' => 'role', 'created_at' => 'created_at'];

    public function index(IndexWarehouseLocationAssignmentRequest $request): AnonymousResourceCollection
    {
        $query = WarehouseLocationAssignment::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['warehouse_id', 'location_id', 'role'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, 'role');

        return WarehouseLocationAssignmentResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreWarehouseLocationAssignmentRequest $request, CreateWarehouseLocationAssignment $create): JsonResponse
    {
        return (new WarehouseLocationAssignmentResource($create($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(ShowWarehouseLocationAssignmentRequest $request, WarehouseLocationAssignment $warehouseLocationRole): WarehouseLocationAssignmentResource
    {
        return new WarehouseLocationAssignmentResource($warehouseLocationRole->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateWarehouseLocationAssignmentRequest $request, WarehouseLocationAssignment $warehouseLocationRole, UpdateWarehouseLocationAssignment $update): WarehouseLocationAssignmentResource
    {
        return new WarehouseLocationAssignmentResource($update($warehouseLocationRole, $request->validated()));
    }

    public function destroy(Request $request, WarehouseLocationAssignment $warehouseLocationRole, DeleteWarehouseLocationAssignment $delete): Response
    {
        abort_unless($request->user()?->can('inventory.location.manage'), 403);
        $delete($warehouseLocationRole);

        return response()->noContent();
    }
}
