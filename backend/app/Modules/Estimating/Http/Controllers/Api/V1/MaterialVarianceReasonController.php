<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\CreateMaterialVarianceReason;
use App\Modules\Estimating\Actions\UpdateMaterialVarianceReason;
use App\Modules\Estimating\Http\Requests\MaterialVarianceReason\IndexMaterialVarianceReasonRequest;
use App\Modules\Estimating\Http\Requests\MaterialVarianceReason\StoreMaterialVarianceReasonRequest;
use App\Modules\Estimating\Http\Requests\MaterialVarianceReason\UpdateMaterialVarianceReasonRequest;
use App\Modules\Estimating\Http\Resources\MaterialVarianceReasonResource;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialVarianceReasonController extends Controller
{
    use AppliesApiQuery;

    private const SORTABLE = ['code' => 'code', 'name' => 'name', 'created_at' => 'created_at'];

    public function index(IndexMaterialVarianceReasonRequest $request): AnonymousResourceCollection
    {
        $query = MaterialVarianceReason::query();
        if ($request->filled('filter.active')) {
            $query->where('active', $request->boolean('filter.active'));
        }
        if ($request->filled('filter.search')) {
            $term = '%'.strtolower((string) $request->input('filter.search')).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term]));
        }
        $this->applySort($query, $request, self::SORTABLE, 'name');

        return MaterialVarianceReasonResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreMaterialVarianceReasonRequest $request, CreateMaterialVarianceReason $create): JsonResponse
    {
        return (new MaterialVarianceReasonResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateMaterialVarianceReasonRequest $request, MaterialVarianceReason $material_variance_reason, UpdateMaterialVarianceReason $update): MaterialVarianceReasonResource
    {
        return new MaterialVarianceReasonResource($update($material_variance_reason, $request->validated(), $request->user()));
    }
}
