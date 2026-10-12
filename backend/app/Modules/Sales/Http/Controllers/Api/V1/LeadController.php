<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AddSalesNote;
use App\Modules\Sales\Actions\AssignLead;
use App\Modules\Sales\Actions\CreateOpportunity;
use App\Modules\Sales\Actions\DecideLead;
use App\Modules\Sales\Actions\LinkLeadParty;
use App\Modules\Sales\Actions\RecordLead;
use App\Modules\Sales\Actions\UpdateLead;
use App\Modules\Sales\Http\Requests\Lead\AddLeadNoteRequest;
use App\Modules\Sales\Http\Requests\Lead\AssignLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\ConvertLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\DisqualifyLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\IndexLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\LinkLeadPartyRequest;
use App\Modules\Sales\Http\Requests\Lead\QualifyLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\ReviveLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\ShowLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\StoreLeadRequest;
use App\Modules\Sales\Http\Requests\Lead\UpdateLeadRequest;
use App\Modules\Sales\Http\Resources\LeadResource;
use App\Modules\Sales\Http\Resources\OpportunityResource;
use App\Modules\Sales\Http\Resources\SalesNoteResource;
use App\Modules\Sales\Models\Lead;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['party' => 'party', 'opportunities' => 'opportunities', 'notes' => 'notes.author', 'history' => 'history.actor'];

    /** @var array<string, string> */
    private const SORTABLE = ['received_at' => 'received_at', 'number' => 'number', 'created_at' => 'created_at'];

    public function index(IndexLeadRequest $request): AnonymousResourceCollection
    {
        $query = Lead::query()->withDecisionMarker();
        $this->applyIncludes($query, $request, ['party' => 'party']);
        $query->with('assignee');
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.assigned_to')) {
            $query->where('assigned_to', $request->integer('filter.assigned_to'));
        }
        if ($request->boolean('filter.unassigned')) {
            $query->whereNull('assigned_to');
        }
        if ($request->filled('filter.party_id')) {
            $query->where('party_id', $request->integer('filter.party_id'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('enquirer_name', 'ilike', "%{$search}%")
                ->orWhere('enquirer_organisation', 'ilike', "%{$search}%")
                ->orWhere('number', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, '-received_at');

        return LeadResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreLeadRequest $request, RecordLead $record): JsonResponse
    {
        return (new LeadResource($record($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowLeadRequest $request, Lead $lead): LeadResource
    {
        return new LeadResource($lead->load(array_merge(['assignee'], $this->requestedIncludes($request, self::INCLUDABLE))));
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLead $update): LeadResource
    {
        return new LeadResource($update($lead, $request->validated(), $request->user()));
    }

    public function linkParty(LinkLeadPartyRequest $request, Lead $lead, LinkLeadParty $link): LeadResource
    {
        return new LeadResource($link($lead, $request->integer('party_id'), $request->user()));
    }

    public function qualify(QualifyLeadRequest $request, Lead $lead, DecideLead $decide): LeadResource
    {
        return new LeadResource($decide->qualify($lead, $request->user(), $request->input('reason')));
    }

    public function disqualify(DisqualifyLeadRequest $request, Lead $lead, DecideLead $decide): JsonResponse
    {
        $expected = $request->input('expected_decision_event_id');
        $done = $decide->disqualify($lead, $request->user(), $request->input('reason'), $request->input('request_key'), $expected === null ? null : (int) $expected);
        $operation = $done['operation'];
        $marker = $operation['decision_event_id'] ?? null;

        // `operation_result` is what this request decided, fixed forever; `data` is the enquiry as it is now.
        return response()->json([
            'data' => new LeadResource($done['lead']),
            'operation_result' => [
                'version' => (int) ($operation['version'] ?? 1),
                'lead_id' => (int) $operation['lead_id'],
                'status' => (string) $operation['status'],
                'decision_event_id' => $marker === null ? null : (int) $marker,
                'changed' => (bool) $operation['changed'],
            ],
            'replayed' => (bool) $done['replayed'],
        ]);
    }

    public function revive(ReviveLeadRequest $request, Lead $lead, DecideLead $decide): LeadResource
    {
        return new LeadResource($decide->revive($lead, $request->user(), $request->input('reason')));
    }

    public function assign(AssignLeadRequest $request, Lead $lead, AssignLead $assign): LeadResource
    {
        $to = $request->input('assigned_to');

        return new LeadResource($assign($lead, $to === null ? null : (int) $to, $request->user()));
    }

    /** One enquiry may yield several genuine opportunities; the same conversion key never yields a second copy of one. */
    public function convert(ConvertLeadRequest $request, Lead $lead, CreateOpportunity $create): JsonResponse
    {
        $result = $create($request->validated(), $request->user(), $lead);

        return (new OpportunityResource($result['opportunity']))->response()->setStatusCode($result['replayed'] ? 200 : 201);
    }

    public function addNote(AddLeadNoteRequest $request, Lead $lead, AddSalesNote $add): JsonResponse
    {
        return (new SalesNoteResource($add($lead, $request->string('body')->toString(), $request->user())))->response()->setStatusCode(201);
    }
}
