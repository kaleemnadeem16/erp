<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\CreateRateDimensionValue;
use App\Modules\Estimating\Actions\DeleteRateDimensionValue;
use App\Modules\Estimating\Actions\UpdateRateDimensionValue;
use App\Modules\Estimating\Http\Requests\RateDimensionValue\IndexRateDimensionValueRequest;
use App\Modules\Estimating\Http\Requests\RateDimensionValue\StoreRateDimensionValueRequest;
use App\Modules\Estimating\Http\Requests\RateDimensionValue\UpdateRateDimensionValueRequest;
use App\Modules\Estimating\Http\Resources\RateDimensionValueResource;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nested under its parent for index/store (a value only ever makes sense as
 * "this dimension's value"), promoted to a flat route for update/destroy once
 * the caller has the value's own id — same shape as SupplierSiteController.
 */
class RateDimensionValueController extends Controller
{
    public function index(IndexRateDimensionValueRequest $request, RateDimension $rate_dimension): AnonymousResourceCollection
    {
        return RateDimensionValueResource::collection(
            $rate_dimension->values()->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StoreRateDimensionValueRequest $request, RateDimension $rate_dimension, CreateRateDimensionValue $create): JsonResponse
    {
        return (new RateDimensionValueResource($create($rate_dimension, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateRateDimensionValueRequest $request, RateDimensionValue $rate_dimension_value, UpdateRateDimensionValue $update): RateDimensionValueResource
    {
        return new RateDimensionValueResource($update($rate_dimension_value, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, RateDimensionValue $rate_dimension_value, DeleteRateDimensionValue $delete): Response
    {
        abort_unless($request->user()?->can('estimating.rate-dimension.manage'), 403);
        $delete($rate_dimension_value, $request->user());

        return response()->noContent();
    }
}
