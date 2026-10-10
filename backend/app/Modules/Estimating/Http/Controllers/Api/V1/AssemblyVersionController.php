<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\CreateAssemblyVersion;
use App\Modules\Estimating\Actions\DeleteAssemblyVersion;
use App\Modules\Estimating\Actions\ExplodeAssemblyVersion;
use App\Modules\Estimating\Actions\UpdateAssemblyVersion;
use App\Modules\Estimating\Http\Requests\AssemblyVersion\StoreAssemblyVersionRequest;
use App\Modules\Estimating\Http\Requests\AssemblyVersion\UpdateAssemblyVersionRequest;
use App\Modules\Estimating\Http\Requests\CostBreakdownRequest;
use App\Modules\Estimating\Http\Resources\AssemblyVersionResource;
use App\Modules\Estimating\Http\Resources\Costing\AssemblyCostResource;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nested under its parent for store (a version only ever makes sense as
 * "this assembly's next revision"), promoted to a flat route for update/
 * destroy/activate/cost-breakdown once the caller has the version's own id
 * — same shape as FormulationVersionController.
 */
class AssemblyVersionController extends Controller
{
    public function store(StoreAssemblyVersionRequest $request, Assembly $assembly, CreateAssemblyVersion $create): JsonResponse
    {
        return (new AssemblyVersionResource($create($assembly, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateAssemblyVersionRequest $request, AssemblyVersion $assembly_version, UpdateAssemblyVersion $update): AssemblyVersionResource
    {
        return new AssemblyVersionResource($update($assembly_version, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, AssemblyVersion $assembly_version, DeleteAssemblyVersion $delete): Response
    {
        abort_unless($request->user()?->can('estimating.assembly.manage'), 403);
        $delete($assembly_version, $request->user());

        return response()->noContent();
    }

    public function activate(Request $request, AssemblyVersion $assembly_version, ActivateAssemblyVersion $activate): AssemblyVersionResource
    {
        abort_unless($request->user()?->can('estimating.assembly.manage'), 403);

        return new AssemblyVersionResource($activate($assembly_version, $request->user()));
    }

    /**
     * Read-only five-part cost/unit breakdown — see
     * docs/domain/assemblies.md#cost-roll-up and ExplodeAssemblyVersion.
     * Explainable by design: which version, which overhead scope tier and
     * driver resolved, what each component's resolved qty/unit-cost/source
     * was, and the final cost/sell per unit — everything a quotation,
     * job-costing or audit screen will eventually need.
     */
    public function costBreakdown(CostBreakdownRequest $request, AssemblyVersion $assembly_version, ExplodeAssemblyVersion $explode): AssemblyCostResource
    {
        abort_unless($request->user()?->can('estimating.internal_cost.view'), 403);

        return new AssemblyCostResource($explode($assembly_version, $request->user()));
    }
}
