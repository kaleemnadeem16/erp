<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Actions\AddSalesNote;
use App\Modules\Sales\Actions\ChangeOpportunityStage;
use App\Modules\Sales\Actions\CloseOpportunity;
use App\Modules\Sales\Actions\CorrectOpportunityAttribution;
use App\Modules\Sales\Actions\CreateOpportunity;
use App\Modules\Sales\Actions\ReadOpportunityAttributionMembers;
use App\Modules\Sales\Actions\ReassignOpportunity;
use App\Modules\Sales\Actions\ReopenOpportunity;
use App\Modules\Sales\Actions\UpdateOpportunity;
use App\Modules\Sales\Http\Requests\Opportunity\AddOpportunityNoteRequest;
use App\Modules\Sales\Http\Requests\Opportunity\ChangeOpportunityStageRequest;
use App\Modules\Sales\Http\Requests\Opportunity\CloseOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\IndexOpportunityAttributionMemberRequest;
use App\Modules\Sales\Http\Requests\Opportunity\IndexOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\ReassignOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\ReopenOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\ShowOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\StoreOpportunityAttributionCorrectionRequest;
use App\Modules\Sales\Http\Requests\Opportunity\StoreOpportunityRequest;
use App\Modules\Sales\Http\Requests\Opportunity\UpdateOpportunityRequest;
use App\Modules\Sales\Http\Resources\OpportunityResource;
use App\Modules\Sales\Http\Resources\SalesNoteResource;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpportunityController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['party' => 'party', 'owner' => 'owner', 'stage' => 'stage', 'lead' => 'lead', 'notes' => 'notes.author', 'history' => 'history.actor', 'attribution_corrections' => 'attributionCorrections.actor'];

    /** @var array<string, string> */
    private const SORTABLE = ['created_at' => 'created_at', 'number' => 'number', 'title' => 'title', 'expected_close_on' => 'expected_close_on', 'next_action_on' => 'next_action_on'];

    public function index(IndexOpportunityRequest $request): AnonymousResourceCollection
    {
        $query = Opportunity::query();
        // The list screens always need the owner to flag who needs replacing; the others are opt-in.
        $this->applyIncludes($query, $request, ['party' => 'party', 'owner' => 'owner', 'stage' => 'stage']);
        $query->with('owner');
        if ($request->filled('filter.outcome')) {
            $query->where('outcome', $request->input('filter.outcome'));
        }
        foreach (['stage_id', 'owner_id', 'party_id'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->integer("filter.{$field}"));
            }
        }
        if ($request->boolean('filter.mine')) {
            $query->where('owner_id', $request->user()?->id);
        }
        if ($request->boolean('filter.needs_attention')) {
            $query->where('outcome', OpportunityOutcome::Open->value)
                ->whereHas('owner', fn (Builder $owner) => $owner->where('status', '!=', MemberStatus::Active->value));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('title', 'ilike', "%{$search}%")
                ->orWhere('number', 'ilike', "%{$search}%")
                ->orWhereHas('party', fn (Builder $p) => $p->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")));
        }
        $this->applySort($query, $request, self::SORTABLE, '-created_at');

        return OpportunityResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreOpportunityRequest $request, CreateOpportunity $create): JsonResponse
    {
        $result = $create($request->validated(), $request->user());

        return (new OpportunityResource($result['opportunity']->load('owner')))->response()->setStatusCode(201);
    }

    public function show(ShowOpportunityRequest $request, Opportunity $opportunity): OpportunityResource
    {
        return new OpportunityResource($opportunity->load(array_merge(['owner'], $this->requestedIncludes($request, self::INCLUDABLE))));
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity, UpdateOpportunity $update): OpportunityResource
    {
        return new OpportunityResource($update($opportunity, $request->validated(), $request->user())->load('owner'));
    }

    public function stage(ChangeOpportunityStageRequest $request, Opportunity $opportunity, ChangeOpportunityStage $change): OpportunityResource
    {
        return new OpportunityResource($change($opportunity, $request->integer('stage_id'), $request->user())->load('owner'));
    }

    public function reassign(ReassignOpportunityRequest $request, Opportunity $opportunity, ReassignOpportunity $reassign): OpportunityResource
    {
        return new OpportunityResource($reassign($opportunity, $request->integer('owner_id'), $request->user(), $request->input('reason'))->load('owner'));
    }

    public function close(CloseOpportunityRequest $request, Opportunity $opportunity, CloseOpportunity $close): OpportunityResource
    {
        return new OpportunityResource($close($opportunity, OpportunityOutcome::from($request->string('outcome')->toString()), $request->input('reason'), $request->user())->load('owner'));
    }

    public function reopen(ReopenOpportunityRequest $request, Opportunity $opportunity, ReopenOpportunity $reopen): OpportunityResource
    {
        return new OpportunityResource($reopen($opportunity, $request->input('reason'), $request->user())->load('owner'));
    }

    public function addNote(AddOpportunityNoteRequest $request, Opportunity $opportunity, AddSalesNote $add): JsonResponse
    {
        return (new SalesNoteResource($add($opportunity, $request->string('body')->toString(), $request->user())))->response()->setStatusCode(201);
    }

    public function attributionCorrection(StoreOpportunityAttributionCorrectionRequest $request, Opportunity $opportunity, CorrectOpportunityAttribution $correct): JsonResponse
    {
        $done = $correct(
            $opportunity, $request->integer('target_event_id'), (string) $request->input('target_field'), $request->integer('asserted_owner_id'),
            $request->input('expected_predecessor_id') === null ? null : $request->integer('expected_predecessor_id'), $request->integer('expected_closure_event_id'),
            $request->input('reason'), $request->input('request_key'), $request->user(),
        );
        $operation = $done['operation'];
        $correction = $operation['correction_id'] ?? null;
        $head = $operation['head_id'] ?? null;

        // `operation_result` is what this request decided, fixed forever; `data` is the opportunity as it is now (never changed by a correction).
        return response()->json([
            'data' => new OpportunityResource($done['opportunity']),
            'operation_result' => [
                'version' => (int) ($operation['version'] ?? 1),
                'opportunity_id' => (int) $operation['opportunity_id'],
                'correction_id' => $correction === null ? null : (int) $correction,
                'head_id' => $head === null ? null : (int) $head,
                'changed' => (bool) $operation['changed'],
            ],
            'replayed' => (bool) $done['replayed'],
        ]);
    }

    public function attributionMembers(IndexOpportunityAttributionMemberRequest $request, Opportunity $opportunity, ReadOpportunityAttributionMembers $read): JsonResponse
    {
        $id = $request->input('filter.id');

        return response()->json(['data' => $read($opportunity, $request->input('filter.search'), $id === null ? null : (int) $id, $request->user())]);
    }
}
