<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\CreateResourceRate;
use App\Modules\Estimating\Actions\DeleteResourceRate;
use App\Modules\Estimating\Http\Requests\ResourceRate\IndexResourceRateRequest;
use App\Modules\Estimating\Http\Requests\ResourceRate\ShowResourceRateRequest;
use App\Modules\Estimating\Http\Requests\ResourceRate\StoreResourceRateRequest;
use App\Modules\Estimating\Http\Resources\ResourceRateResource;
use App\Modules\Estimating\Models\ResourceRate;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * No update endpoint — a rate change is a new dated row, never an edit to
 * an already-used historical one, see ResourceRate. Delete has no "in use"
 * guard yet, but still requires the resource-rate management capability.
 */
class ResourceRateController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['item' => 'item', 'rateUom' => 'rateUom'];

    private const SORTABLE = ['effective_from' => 'effective_from', 'created_at' => 'created_at'];

    public function index(IndexResourceRateRequest $request): AnonymousResourceCollection
    {
        $query = ResourceRate::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.item_id')) {
            $query->where('item_id', $request->integer('filter.item_id'));
        }
        $this->applySort($query, $request, self::SORTABLE, '-effective_from');

        return ResourceRateResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreResourceRateRequest $request, CreateResourceRate $create): JsonResponse
    {
        return (new ResourceRateResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowResourceRateRequest $request, ResourceRate $resource_rate): ResourceRateResource
    {
        return new ResourceRateResource($resource_rate->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function destroy(Request $request, ResourceRate $resource_rate, DeleteResourceRate $delete): Response
    {
        abort_unless($request->user()?->can('estimating.resource-rate.manage'), 403);
        $delete($resource_rate, $request->user());

        return response()->noContent();
    }
}
