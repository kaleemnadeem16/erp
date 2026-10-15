<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Actions\CreatePartyContact;
use App\Modules\Parties\Actions\UpdatePartyContact;
use App\Modules\Parties\Http\Requests\PartyContact\IndexPartyContactRequest;
use App\Modules\Parties\Http\Requests\PartyContact\StorePartyContactRequest;
use App\Modules\Parties\Http\Requests\PartyContact\UpdatePartyContactRequest;
use App\Modules\Parties\Http\Resources\PartyContactResource;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The people at a Party. Nested under the Party for index/store (a contact only ever makes sense as "this Party's contact"), flat for update once the caller has the
 * contact's own id. Removing a contact is not offered yet: a deal or enquiry may point at one, and archiving needs its own rule.
 */
class PartyContactController extends Controller
{
    public function index(IndexPartyContactRequest $request, Party $party): AnonymousResourceCollection
    {
        $query = $party->contacts()->orderByDesc('is_primary')->orderBy('name');
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"));
        }

        return PartyContactResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StorePartyContactRequest $request, Party $party, CreatePartyContact $create): JsonResponse
    {
        return (new PartyContactResource($create($party, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdatePartyContactRequest $request, PartyContact $party_contact, UpdatePartyContact $update): PartyContactResource
    {
        return new PartyContactResource($update($party_contact, $request->validated(), $request->user()));
    }
}
