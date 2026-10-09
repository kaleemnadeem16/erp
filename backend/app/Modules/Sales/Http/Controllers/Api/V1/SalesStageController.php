<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\MaintainSalesStages;
use App\Modules\Sales\Http\Requests\Stage\IndexStageRequest;
use App\Modules\Sales\Http\Requests\Stage\SeedSuggestedStagesRequest;
use App\Modules\Sales\Http\Requests\Stage\StoreStageRequest;
use App\Modules\Sales\Http\Requests\Stage\UpdateStageRequest;
use App\Modules\Sales\Http\Resources\SalesStageResource;
use App\Modules\Sales\Models\SalesStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesStageController extends Controller
{
    public function index(IndexStageRequest $request): AnonymousResourceCollection
    {
        return SalesStageResource::collection(SalesStage::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(StoreStageRequest $request, MaintainSalesStages $stages): JsonResponse
    {
        /** @var array{code: string, name: string, sort_order?: int} $data */
        $data = $request->validated();

        return (new SalesStageResource($stages->create($data, $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateStageRequest $request, SalesStage $stage, MaintainSalesStages $stages): SalesStageResource
    {
        return new SalesStageResource($stages->update($stage, $request->validated(), $request->user()));
    }

    /** Start from Qualification → Proposal → Negotiation when no stage exists yet. */
    public function seedSuggested(SeedSuggestedStagesRequest $request, MaintainSalesStages $stages): AnonymousResourceCollection
    {
        return SalesStageResource::collection($stages->seedSuggested($request->user()));
    }
}
