<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ArchiveFormulation;
use App\Modules\Estimating\Actions\CreateFormulation;
use App\Modules\Estimating\Actions\ResolveFormulation;
use App\Modules\Estimating\Actions\UpdateFormulation;
use App\Modules\Estimating\Http\Requests\Formulation\IndexFormulationRequest;
use App\Modules\Estimating\Http\Requests\Formulation\ResolveFormulationRequest;
use App\Modules\Estimating\Http\Requests\Formulation\ShowFormulationRequest;
use App\Modules\Estimating\Http\Requests\Formulation\StoreFormulationRequest;
use App\Modules\Estimating\Http\Requests\Formulation\UpdateFormulationRequest;
use App\Modules\Estimating\Http\Resources\FormulationResource;
use App\Modules\Estimating\Models\Formulation;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class FormulationController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = [
        'outputItem' => 'outputItem', 'outputUom' => 'outputUom', 'customer' => 'customer',
        'versions' => 'versions', 'versions.lines' => 'versions.lines',
        'versions.lines.inputItem' => 'versions.lines.inputItem',
        'versions.lines.uom' => 'versions.lines.uom',
        'activeVersion' => 'activeVersion',
    ];

    private const SORTABLE = ['code' => 'code', 'created_at' => 'created_at'];

    public function index(IndexFormulationRequest $request): AnonymousResourceCollection
    {
        $query = Formulation::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['output_item_id', 'customer_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return FormulationResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreFormulationRequest $request, CreateFormulation $create): JsonResponse
    {
        return (new FormulationResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowFormulationRequest $request, Formulation $formulation): FormulationResource
    {
        return new FormulationResource($formulation->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateFormulationRequest $request, Formulation $formulation, UpdateFormulation $update): FormulationResource
    {
        return new FormulationResource($update($formulation, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Formulation $formulation, ArchiveFormulation $archive): Response
    {
        abort_unless($request->user()?->can('estimating.formulation.manage'), 403);
        $archive($formulation, $request->user());

        return response()->noContent();
    }

    /**
     * Read-only preview of docs/domain/formulations.md#customer-specific-formulations
     * — the same customer-override-then-default resolution assemblies will use
     * once that slice lands.
     */
    public function resolve(ResolveFormulationRequest $request, ResolveFormulation $resolve): FormulationResource
    {
        $customerId = $request->filled('customer_id') ? $request->integer('customer_id') : null;

        return new FormulationResource($resolve($request->integer('output_item_id'), $customerId));
    }
}
