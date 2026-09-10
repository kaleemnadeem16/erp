<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\ReviewNegativeStockOverride;
use App\Modules\Inventory\Http\Requests\NegativeStockOverride\DecideNegativeStockOverrideRequestRequest;
use App\Modules\Inventory\Http\Requests\NegativeStockOverride\IndexNegativeStockOverrideRequestRequest;
use App\Modules\Inventory\Http\Requests\NegativeStockOverride\ReviewNegativeStockOverrideRequestRequest;
use App\Modules\Inventory\Http\Requests\NegativeStockOverride\ShowNegativeStockOverrideRequestRequest;
use App\Modules\Inventory\Http\Requests\NegativeStockOverride\StoreNegativeStockOverrideRequestRequest;
use App\Modules\Inventory\Http\Resources\NegativeStockOverrideRequestResource;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The header (NegativeStockOverrideRequest) is create/read-only over HTTP —
 * every state change is a named action (decide, review), each delegating
 * straight to its Action; see docs/domain/negative-stock.md. There is no
 * status column: the frontend derives display status from
 * authorisation_mode plus whether approval/review exists.
 */
class NegativeStockOverrideRequestController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'item' => 'item', 'location' => 'location', 'batch' => 'batch',
        'approval' => 'approval', 'review' => 'review',
    ];

    /** @var array<string, string> */
    private const SORTABLE = ['requested_at' => 'requested_at'];

    public function index(IndexNegativeStockOverrideRequestRequest $request): AnonymousResourceCollection
    {
        $query = NegativeStockOverrideRequest::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.movement_class')) {
            $query->where('movement_class', $request->input('filter.movement_class'));
        }
        if ($request->filled('filter.authorisation_mode')) {
            $query->where('authorisation_mode', $request->input('filter.authorisation_mode'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->whereHas('item', fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%"));
        }

        $this->applySort($query, $request, self::SORTABLE, '-requested_at');

        return NegativeStockOverrideRequestResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreNegativeStockOverrideRequestRequest $request, RequestNegativeStockOverride $requestOverride): JsonResponse
    {
        $override = $requestOverride($request->validated(), $request->user());

        return (new NegativeStockOverrideRequestResource($override))->response()->setStatusCode(201);
    }

    public function show(ShowNegativeStockOverrideRequestRequest $request, NegativeStockOverrideRequest $negativeStockOverrideRequest): NegativeStockOverrideRequestResource
    {
        return new NegativeStockOverrideRequestResource($negativeStockOverrideRequest->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function decide(DecideNegativeStockOverrideRequestRequest $request, NegativeStockOverrideRequest $negativeStockOverrideRequest, DecideNegativeStockOverride $decide): NegativeStockOverrideRequestResource
    {
        $decide(
            $negativeStockOverrideRequest,
            NegativeStockApprovalDecision::from($request->validated('decision')),
            $request->validated('notes'),
            $request->user(),
        );

        return new NegativeStockOverrideRequestResource($negativeStockOverrideRequest->refresh()->load('approval'));
    }

    public function review(ReviewNegativeStockOverrideRequestRequest $request, NegativeStockOverrideRequest $negativeStockOverrideRequest, ReviewNegativeStockOverride $review): NegativeStockOverrideRequestResource
    {
        $review(
            $negativeStockOverrideRequest,
            NegativeStockReviewOutcome::from($request->validated('outcome')),
            $request->validated('notes'),
            $request->user(),
        );

        return new NegativeStockOverrideRequestResource($negativeStockOverrideRequest->refresh()->load('review'));
    }
}
