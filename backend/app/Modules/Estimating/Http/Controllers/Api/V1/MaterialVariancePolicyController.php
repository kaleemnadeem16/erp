<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ActivateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\DeleteMaterialVariancePolicy;
use App\Modules\Estimating\Actions\UpdateMaterialVariancePolicy;
use App\Modules\Estimating\Http\Requests\MaterialVariancePolicy\IndexMaterialVariancePolicyRequest;
use App\Modules\Estimating\Http\Requests\MaterialVariancePolicy\ShowMaterialVariancePolicyRequest;
use App\Modules\Estimating\Http\Requests\MaterialVariancePolicy\StoreMaterialVariancePolicyRequest;
use App\Modules\Estimating\Http\Requests\MaterialVariancePolicy\UpdateMaterialVariancePolicyRequest;
use App\Modules\Estimating\Http\Resources\MaterialVariancePolicyResource;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class MaterialVariancePolicyController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['changedBy' => 'changedBy', 'bands' => 'bands.uom'];

    private const SORTABLE = ['effective_from' => 'effective_from', 'created_at' => 'created_at'];

    public function index(IndexMaterialVariancePolicyRequest $request): AnonymousResourceCollection
    {
        $query = MaterialVariancePolicy::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['scope_type', 'scope_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return MaterialVariancePolicyResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(ShowMaterialVariancePolicyRequest $request, MaterialVariancePolicy $material_variance_policy): MaterialVariancePolicyResource
    {
        return new MaterialVariancePolicyResource($material_variance_policy->load(['bands.uom', ...$this->requestedIncludes($request, self::INCLUDABLE)]));
    }

    public function store(StoreMaterialVariancePolicyRequest $request, CreateMaterialVariancePolicy $create): JsonResponse
    {
        return (new MaterialVariancePolicyResource($create($request->validated(), $request->user()->id)))->response()->setStatusCode(201);
    }

    public function update(UpdateMaterialVariancePolicyRequest $request, MaterialVariancePolicy $material_variance_policy, UpdateMaterialVariancePolicy $update): MaterialVariancePolicyResource
    {
        return new MaterialVariancePolicyResource($update($material_variance_policy, $request->validated()));
    }

    public function destroy(Request $request, MaterialVariancePolicy $material_variance_policy, DeleteMaterialVariancePolicy $delete): Response
    {
        $this->assertMayManage($request, 'delete');
        $delete($material_variance_policy);

        return response()->noContent();
    }

    public function activate(Request $request, MaterialVariancePolicy $material_variance_policy, ActivateMaterialVariancePolicy $activate): MaterialVariancePolicyResource
    {
        $this->assertMayManage($request, 'activate');

        return new MaterialVariancePolicyResource($activate($material_variance_policy));
    }

    private function assertMayManage(Request $request, string $verb): void
    {
        if (! $request->user()?->can('estimating.material_variance_policy.manage')) {
            throw new DomainInvariantException("The actor is not permitted to {$verb} a material variance policy.", 'status');
        }
    }
}
