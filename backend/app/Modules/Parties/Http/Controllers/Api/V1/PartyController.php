<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Http\Requests\Party\IndexPartyRequest;
use App\Modules\Parties\Http\Requests\Party\StorePartyRequest;
use App\Modules\Parties\Http\Requests\Party\UpdatePartyRequest;
use App\Modules\Parties\Http\Resources\PartyResource;
use App\Modules\Parties\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartyController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'customer_account' => 'customerAccount',
        'supplier_account' => 'supplierAccount',
    ];

    public function index(IndexPartyRequest $request): AnonymousResourceCollection
    {
        $query = Party::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        $query->orderBy('name');

        $perPage = $request->integer('per_page', 25);

        return PartyResource::collection($query->paginate($perPage));
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        $party = Party::create($request->validated());

        // status/custom_fields fall back to a Postgres column default when
        // omitted — see ItemController::store() for the same fix and why.
        $party->refresh();

        return (new PartyResource($party))->response()->setStatusCode(201);
    }

    public function show(Request $request, Party $party): PartyResource
    {
        return new PartyResource($party->load($this->requestedIncludes($request)));
    }

    public function update(UpdatePartyRequest $request, Party $party): PartyResource
    {
        $party->update($request->validated());

        return new PartyResource($party);
    }

    public function destroy(Party $party): JsonResponse
    {
        $party->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<Party>  $query
     */
    private function applyIncludes(Builder $query, Request $request): void
    {
        foreach ($this->requestedIncludes($request) as $relation) {
            $query->with($relation);
        }
    }

    /**
     * @return list<string>
     */
    private function requestedIncludes(Request $request): array
    {
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        return array_values(array_intersect_key(self::INCLUDABLE, array_flip($requested)));
    }
}
