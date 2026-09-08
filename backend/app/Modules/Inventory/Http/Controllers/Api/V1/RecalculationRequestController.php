<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Actions\ResumeRecalculationRegeneration;
use App\Modules\Inventory\Http\Requests\Recalculation\DecideRecalculationRequestRequest;
use App\Modules\Inventory\Http\Requests\Recalculation\IndexRecalculationRequestRequest;
use App\Modules\Inventory\Http\Requests\Recalculation\ResumeRecalculationRegenerationRequest;
use App\Modules\Inventory\Http\Requests\Recalculation\ShowRecalculationRequestRequest;
use App\Modules\Inventory\Http\Requests\Recalculation\StoreRecalculationRequestRequest;
use App\Modules\Inventory\Http\Resources\RecalculationRequestResource;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The header (RecalculationRequest) is create/read-only over HTTP — every
 * state change is a named action on the addressable child resource it
 * actually applies to, RecalculationImpactVersion (decide, execute,
 * resume-regeneration); see docs/domain/stock-recalculation.md.
 *
 * decide/execute/resumeRegeneration all return the reloaded *parent*
 * request rather than their own Action's return value: both
 * DecideRecalculationApproval and ExecuteRecalculation revalidate the
 * ledger immediately before acting and can commit a stale-flip (plus
 * usually a fresh replacement version) even when they go on to fail — so a
 * 422 can still mean server state changed. One consistent resource shape
 * lets the frontend simply refetch/replace on both success and error.
 */
class RecalculationRequestController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'item' => 'item', 'location' => 'location',
        'destinationLocation' => 'destinationLocation', 'batch' => 'batch',
    ];

    /** @var array<string, string> */
    private const SORTABLE = ['requested_at' => 'requested_at', 'review_due_at' => 'review_due_at'];

    /** @var list<string> */
    private const IMPACT_VERSION_INCLUDES = ['impactVersions.approvals', 'impactVersions.execution', 'impactVersions.regenerationReview'];

    public function index(IndexRecalculationRequestRequest $request): AnonymousResourceCollection
    {
        $query = RecalculationRequest::query()->with(self::IMPACT_VERSION_INCLUDES);
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.movement_kind')) {
            $query->where('movement_kind', $request->input('filter.movement_kind'));
        }

        $this->applySort($query, $request, self::SORTABLE, '-requested_at');

        return RecalculationRequestResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreRecalculationRequestRequest $request, RequestStockRecalculation $requestRecalculation): JsonResponse
    {
        $recalculation = $requestRecalculation($request->validated(), $request->user());
        $recalculation->load('impactVersions.approvals');

        return (new RecalculationRequestResource($recalculation))->response()->setStatusCode(201);
    }

    public function show(ShowRecalculationRequestRequest $request, RecalculationRequest $recalculationRequest): RecalculationRequestResource
    {
        $includes = array_merge(self::IMPACT_VERSION_INCLUDES, $this->requestedIncludes($request, self::INCLUDABLE));

        return new RecalculationRequestResource($recalculationRequest->load($includes));
    }

    public function decide(DecideRecalculationRequestRequest $request, RecalculationImpactVersion $recalculationImpactVersion, DecideRecalculationApproval $decide): RecalculationRequestResource
    {
        $decide(
            $recalculationImpactVersion,
            RecalculationApprovalDecision::from($request->validated('decision')),
            $request->validated('notes'),
            $request->user(),
        );

        return $this->reloadedRequest($recalculationImpactVersion);
    }

    public function execute(Request $request, RecalculationImpactVersion $recalculationImpactVersion, ExecuteRecalculation $execute): RecalculationRequestResource
    {
        $execute($recalculationImpactVersion, $request->user());

        return $this->reloadedRequest($recalculationImpactVersion);
    }

    public function resumeRegeneration(ResumeRecalculationRegenerationRequest $request, RecalculationImpactVersion $recalculationImpactVersion, ResumeRecalculationRegeneration $resume): RecalculationRequestResource
    {
        $resume($recalculationImpactVersion, $request->validated('notes'), $request->user());

        return $this->reloadedRequest($recalculationImpactVersion);
    }

    private function reloadedRequest(RecalculationImpactVersion $version): RecalculationRequestResource
    {
        $recalculation = RecalculationRequest::query()->findOrFail($version->recalculation_request_id);

        return new RecalculationRequestResource($recalculation->load(self::IMPACT_VERSION_INCLUDES));
    }
}
