<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Actions\ArchiveParty;
use App\Modules\Parties\Actions\CreateParty;
use App\Modules\Parties\Actions\UpdateParty;
use App\Modules\Parties\Http\Requests\Party\IndexPartyRequest;
use App\Modules\Parties\Http\Requests\Party\ShowPartyRequest;
use App\Modules\Parties\Http\Requests\Party\StorePartyRequest;
use App\Modules\Parties\Http\Requests\Party\UpdatePartyRequest;
use App\Modules\Parties\Http\Resources\PartyResource;
use App\Modules\Parties\Models\Party;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PartyController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'customer_account' => 'customerAccount',
        'supplier_account' => 'supplierAccount',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'created_at' => 'created_at',
    ];

    public function index(IndexPartyRequest $request): AnonymousResourceCollection
    {
        $query = Party::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'name');

        return PartyResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StorePartyRequest $request, CreateParty $create): JsonResponse
    {
        $party = $create($request->validated(), $request->user());

        return (new PartyResource($party))->response()->setStatusCode(201);
    }

    public function show(ShowPartyRequest $request, Party $party): PartyResource
    {
        return new PartyResource($party->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdatePartyRequest $request, Party $party, UpdateParty $update): PartyResource
    {
        return new PartyResource($update($party, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Party $party, ArchiveParty $archive): Response
    {
        abort_unless($request->user()?->can('parties.party.manage'), 403);
        $archive($party, $request->user());

        return response()->noContent();
    }
}
