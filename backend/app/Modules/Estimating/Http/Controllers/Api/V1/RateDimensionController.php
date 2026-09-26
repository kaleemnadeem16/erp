<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ArchiveRateDimension;
use App\Modules\Estimating\Actions\CreateRateDimension;
use App\Modules\Estimating\Actions\UpdateRateDimension;
use App\Modules\Estimating\Http\Requests\RateDimension\IndexRateDimensionRequest;
use App\Modules\Estimating\Http\Requests\RateDimension\ShowRateDimensionRequest;
use App\Modules\Estimating\Http\Requests\RateDimension\StoreRateDimensionRequest;
use App\Modules\Estimating\Http\Requests\RateDimension\UpdateRateDimensionRequest;
use App\Modules\Estimating\Http\Resources\RateDimensionResource;
use App\Modules\Estimating\Models\RateDimension;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class RateDimensionController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['values' => 'values'];

    private const SORTABLE = ['sort_order' => 'sort_order', 'code' => 'code', 'created_at' => 'created_at'];

    public function index(IndexRateDimensionRequest $request): AnonymousResourceCollection
    {
        $query = RateDimension::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'sort_order');

        return RateDimensionResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreRateDimensionRequest $request, CreateRateDimension $create): JsonResponse
    {
        return (new RateDimensionResource($create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowRateDimensionRequest $request, RateDimension $rate_dimension): RateDimensionResource
    {
        return new RateDimensionResource($rate_dimension->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateRateDimensionRequest $request, RateDimension $rate_dimension, UpdateRateDimension $update): RateDimensionResource
    {
        return new RateDimensionResource($update($rate_dimension, $request->validated()));
    }

    public function destroy(RateDimension $rate_dimension, ArchiveRateDimension $archive): Response
    {
        $archive($rate_dimension);

        return response()->noContent();
    }
}
