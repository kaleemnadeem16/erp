<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\CreateWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\DeleteWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\UpdateWarehouseLocationAssignment;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\StoreWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment\UpdateWarehouseLocationAssignmentRequest;
use App\Modules\Inventory\Http\Resources\WarehouseLocationAssignmentResource;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class WarehouseLocationAssignmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WarehouseLocationAssignmentResource::collection(
            WarehouseLocationAssignment::query()->with(['warehouse', 'location'])->orderBy('warehouse_id')->orderBy('role')->paginate(25)
        );
    }

    public function store(StoreWarehouseLocationAssignmentRequest $request, CreateWarehouseLocationAssignment $create): JsonResponse
    {
        return (new WarehouseLocationAssignmentResource($create($request->validated())->load(['warehouse', 'location'])))
            ->response()->setStatusCode(201);
    }

    public function show(WarehouseLocationAssignment $warehouseLocationRole): WarehouseLocationAssignmentResource
    {
        return new WarehouseLocationAssignmentResource($warehouseLocationRole->load(['warehouse', 'location']));
    }

    public function update(UpdateWarehouseLocationAssignmentRequest $request, WarehouseLocationAssignment $warehouseLocationRole, UpdateWarehouseLocationAssignment $update): WarehouseLocationAssignmentResource
    {
        return new WarehouseLocationAssignmentResource($update($warehouseLocationRole, $request->validated())->load(['warehouse', 'location']));
    }

    public function destroy(WarehouseLocationAssignment $warehouseLocationRole, DeleteWarehouseLocationAssignment $delete): Response
    {
        $delete($warehouseLocationRole);

        return response()->noContent();
    }
}
