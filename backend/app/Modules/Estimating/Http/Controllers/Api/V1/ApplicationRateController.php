<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ArchiveApplicationRate;
use App\Modules\Estimating\Actions\CreateApplicationRate;
use App\Modules\Estimating\Actions\ResolveApplicationRate;
use App\Modules\Estimating\Actions\UpdateApplicationRate;
use App\Modules\Estimating\Http\Requests\ApplicationRate\IndexApplicationRateRequest;
use App\Modules\Estimating\Http\Requests\ApplicationRate\ResolveApplicationRateRequest;
use App\Modules\Estimating\Http\Requests\ApplicationRate\ShowApplicationRateRequest;
use App\Modules\Estimating\Http\Requests\ApplicationRate\StoreApplicationRateRequest;
use App\Modules\Estimating\Http\Requests\ApplicationRate\UpdateApplicationRateRequest;
use App\Modules\Estimating\Http\Resources\ApplicationRateResource;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApplicationRateController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['item' => 'item', 'workUom' => 'workUom', 'uom' => 'uom'];

    private const SORTABLE = ['created_at' => 'created_at'];

    public function index(IndexApplicationRateRequest $request): AnonymousResourceCollection
    {
        $query = ApplicationRate::query()->with('dimensionValues:id,rate_dimension_id');
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['item_id', 'work_uom_id', 'is_default'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, 'created_at');

        return ApplicationRateResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreApplicationRateRequest $request, CreateApplicationRate $create): JsonResponse
    {
        return (new ApplicationRateResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowApplicationRateRequest $request, ApplicationRate $application_rate): ApplicationRateResource
    {
        $application_rate->loadMissing('dimensionValues:id,rate_dimension_id');

        return new ApplicationRateResource($application_rate->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateApplicationRateRequest $request, ApplicationRate $application_rate, UpdateApplicationRate $update): ApplicationRateResource
    {
        return new ApplicationRateResource($update($application_rate, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ApplicationRate $application_rate, ArchiveApplicationRate $archive): Response
    {
        abort_unless($request->user()?->can('estimating.application-rate.manage'), 403);
        $archive($application_rate, $request->user());

        return response()->noContent();
    }

    /**
     * Read-only preview of docs/domain/application-rates.md#resolution — lets
     * the UI show which rate would win for a combination before any document
     * exists to reference one (Phase 4).
     */
    public function resolve(ResolveApplicationRateRequest $request, ResolveApplicationRate $resolve): JsonResponse
    {
        $dimensionValueIds = array_map('intval', $request->input('dimension_value_ids', []));

        $resolution = $resolve($request->integer('item_id'), $request->integer('work_uom_id'), $dimensionValueIds);

        return response()->json([
            'data' => [
                'method' => $resolution->method->value,
                'rate' => new ApplicationRateResource($resolution->rate->loadMissing('dimensionValues:id,rate_dimension_id')),
            ],
        ]);
    }
}
