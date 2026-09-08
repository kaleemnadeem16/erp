<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\ApproveInventoryCutoverNegativeOpening;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CommitInventoryCutoverOpeningState;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\FinaliseInventoryCutover;
use App\Modules\Inventory\Actions\PreviewInventoryCutover;
use App\Modules\Inventory\Actions\RequestInventoryCutoverNegativeOpening;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Http\Requests\InventoryCutover\AttachInventoryCutoverBatchRequest;
use App\Modules\Inventory\Http\Requests\InventoryCutover\IndexInventoryCutoverRequest;
use App\Modules\Inventory\Http\Requests\InventoryCutover\RequestInventoryCutoverNegativeOpeningRequest;
use App\Modules\Inventory\Http\Requests\InventoryCutover\ShowInventoryCutoverRequest;
use App\Modules\Inventory\Http\Requests\InventoryCutover\StoreInventoryCutoverRequest;
use App\Modules\Inventory\Http\Resources\InventoryCutoverBatchResource;
use App\Modules\Inventory\Http\Resources\InventoryCutoverNegativeOpeningResource;
use App\Modules\Inventory\Http\Resources\InventoryCutoverResource;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverNegativeOpening;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The header (InventoryCutover) is create/read-only over HTTP — every state
 * change is a named action, each delegating straight to its Action; see
 * docs/domain/inventory-cutover.md.
 */
class InventoryCutoverController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'batches' => 'batches',
        'batches.sourceFacts' => 'batches.sourceFacts',
        'policyTransitions' => 'policyTransitions',
        'negativeOpenings' => 'negativeOpenings',
        'creator' => 'creator',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'created_at' => 'created_at', 'validated_at' => 'validated_at',
        'approved_at' => 'approved_at', 'finalised_at' => 'finalised_at',
    ];

    public function index(IndexInventoryCutoverRequest $request): AnonymousResourceCollection
    {
        $query = InventoryCutover::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return InventoryCutoverResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreInventoryCutoverRequest $request, CreateInventoryCutover $create): JsonResponse
    {
        $cutover = $create($request->validated(), $request->user());

        return (new InventoryCutoverResource($cutover))->response()->setStatusCode(201);
    }

    public function show(ShowInventoryCutoverRequest $request, InventoryCutover $inventoryCutover): InventoryCutoverResource
    {
        return new InventoryCutoverResource($inventoryCutover->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function storeBatch(AttachInventoryCutoverBatchRequest $request, InventoryCutover $inventoryCutover, AttachInventoryCutoverBatch $attach): JsonResponse
    {
        $importBatch = ImportBatch::query()->findOrFail($request->validated('import_batch_id'));
        // AttachInventoryCutoverBatch's status column relies on a DB-level
        // default (its own InventoryCutoverWorkflowTest reads it the same
        // way), so it isn't populated on the returned instance without this.
        $link = $attach($inventoryCutover, $importBatch, $request->user())->refresh();

        return (new InventoryCutoverBatchResource($link))->response()->setStatusCode(201);
    }

    public function validateCutover(Request $request, InventoryCutover $inventoryCutover, ValidateInventoryCutover $validate): InventoryCutoverResource
    {
        return new InventoryCutoverResource($validate($inventoryCutover, $request->user()));
    }

    public function preview(InventoryCutover $inventoryCutover, PreviewInventoryCutover $preview): JsonResponse
    {
        return response()->json(['data' => $preview($inventoryCutover)]);
    }

    public function approve(Request $request, InventoryCutover $inventoryCutover, ApproveInventoryCutover $approve): InventoryCutoverResource
    {
        return new InventoryCutoverResource($approve($inventoryCutover, $request->user()));
    }

    public function commit(Request $request, InventoryCutover $inventoryCutover, CommitInventoryCutoverOpeningState $commit): InventoryCutoverResource
    {
        return new InventoryCutoverResource($commit($inventoryCutover, $request->user()));
    }

    public function finalise(Request $request, InventoryCutover $inventoryCutover, FinaliseInventoryCutover $finalise): InventoryCutoverResource
    {
        return new InventoryCutoverResource($finalise($inventoryCutover, $request->user()));
    }

    public function requestNegativeOpening(RequestInventoryCutoverNegativeOpeningRequest $request, InventoryCutover $inventoryCutover, RequestInventoryCutoverNegativeOpening $requestNegativeOpening): JsonResponse
    {
        $exception = $requestNegativeOpening($inventoryCutover, $request->validated(), $request->user());

        return (new InventoryCutoverNegativeOpeningResource($exception))->response()->setStatusCode(201);
    }

    public function approveNegativeOpening(Request $request, InventoryCutoverNegativeOpening $inventoryCutoverNegativeOpening, ApproveInventoryCutoverNegativeOpening $approve): InventoryCutoverNegativeOpeningResource
    {
        return new InventoryCutoverNegativeOpeningResource($approve($inventoryCutoverNegativeOpening, $request->user()));
    }
}
