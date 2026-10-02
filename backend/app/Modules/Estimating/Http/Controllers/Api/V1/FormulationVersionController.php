<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Actions\CreateFormulationVersion;
use App\Modules\Estimating\Actions\DeleteFormulationVersion;
use App\Modules\Estimating\Actions\ExplodeFormulationVersion;
use App\Modules\Estimating\Actions\UpdateFormulationVersion;
use App\Modules\Estimating\Http\Requests\FormulationVersion\StoreFormulationVersionRequest;
use App\Modules\Estimating\Http\Requests\FormulationVersion\UpdateFormulationVersionRequest;
use App\Modules\Estimating\Http\Resources\FormulationVersionResource;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationExplosion;
use App\Modules\Estimating\Support\FormulationLineBreakdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nested under its parent for store (a version only ever makes sense as
 * "this formulation's next revision"), promoted to a flat route for
 * update/destroy/activate/cost-breakdown once the caller has the version's
 * own id — same shape as RateDimensionValueController.
 */
class FormulationVersionController extends Controller
{
    public function store(StoreFormulationVersionRequest $request, Formulation $formulation, CreateFormulationVersion $create): JsonResponse
    {
        return (new FormulationVersionResource($create($formulation, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateFormulationVersionRequest $request, FormulationVersion $formulation_version, UpdateFormulationVersion $update): FormulationVersionResource
    {
        return new FormulationVersionResource($update($formulation_version, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, FormulationVersion $formulation_version, DeleteFormulationVersion $delete): Response
    {
        abort_unless($request->user()?->can('estimating.formulation.manage'), 403);
        $delete($formulation_version, $request->user());

        return response()->noContent();
    }

    public function activate(Request $request, FormulationVersion $formulation_version, ActivateFormulationVersion $activate): FormulationVersionResource
    {
        abort_unless($request->user()?->can('estimating.formulation.manage'), 403);

        return new FormulationVersionResource($activate($formulation_version, $request->user()));
    }

    /**
     * Read-only cost/quantity roll-up preview — see
     * docs/domain/formulations.md#cost-of-one-output-unit and
     * ExplodeFormulationVersion. Returns the full per-line breakdown,
     * recursing into any nested formulation, so the UI can show wastage
     * compounding per level rather than only the total (that doc's own
     * "Landmines").
     */
    public function costBreakdown(Request $request, FormulationVersion $formulation_version, ExplodeFormulationVersion $explode): JsonResponse
    {
        abort_unless($request->user()?->can('estimating.internal_cost.view'), 403);

        return response()->json(['data' => $this->serializeExplosion($explode($formulation_version))]);
    }

    /** @return array<string, mixed> */
    private function serializeExplosion(FormulationExplosion $explosion): array
    {
        return [
            'version_id' => $explosion->version->id,
            'total_cost' => (string) $explosion->totalCost,
            'cost_per_output_unit' => (string) $explosion->costPerOutputUnit,
            'lines' => array_map($this->serializeLine(...), $explosion->lines),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLine(FormulationLineBreakdown $breakdown): array
    {
        return [
            'line_id' => $breakdown->line->id,
            'input_item_id' => $breakdown->line->input_item_id,
            'input_item_code' => $breakdown->line->inputItem->code,
            'input_item_name' => $breakdown->line->inputItem->name,
            'qty' => $breakdown->line->qty,
            'wastage_pct' => $breakdown->line->wastage_pct,
            'unit_cost' => (string) $breakdown->unitCost,
            'line_cost' => (string) $breakdown->lineCost,
            'sub_formulation' => $breakdown->subExplosion === null ? null : $this->serializeExplosion($breakdown->subExplosion),
        ];
    }
}
