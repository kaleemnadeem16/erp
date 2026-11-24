<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use Illuminate\Support\Facades\DB;

/**
 * The people and places a person may choose when preparing the handover of ONE accepted service: the accepted customer's own current contacts (with an email or telephone, as
 * the readiness rule requires) and active sites. Same duties as the preview (prepare + customer-decision view), decided under the access fence and the source locks; nothing of any
 * other customer, no directory, no archived row, and no more than the fields the preview itself already shows once chosen. Reads only.
 */
final class ReadHandoverOptions
{
    private const LIMIT = 100;

    public function __construct(private readonly HandoverAccess $access) {}

    /** @return array{acceptance_id:int,contacts:list<array{id:int,name:string,email:string|null,phone:string|null}>,sites:list<array{id:int,name:string,address_line1:string|null,city:string|null,country_code:string|null}>} */
    public function __invoke(User $actor, int $acceptanceId): array
    {
        HandoverAccess::early($actor, true);

        return DB::transaction(function () use ($actor, $acceptanceId): array {
            $s = $this->access->lock($actor, $acceptanceId);
            $partyId = (int) $s->case->party_id;
            $contacts = PartyContact::query()->where('party_id', $partyId)
                ->where(fn ($q) => $q->whereRaw("coalesce(trim(email), '') <> ''")->orWhereRaw("coalesce(trim(phone), '') <> ''"))
                ->orderByDesc('is_primary')->orderBy('name')->orderBy('id')->limit(self::LIMIT)->get(['id', 'name', 'email', 'phone']);
            $sites = PartySite::query()->where('party_id', $partyId)->where('status', 'ACTIVE')->orderBy('name')->orderBy('id')->limit(self::LIMIT)->get(['id', 'name', 'address_line1', 'city', 'country_code']);

            return [
                'acceptance_id' => $acceptanceId,
                'contacts' => $contacts->map(fn (PartyContact $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name, 'email' => $c->email, 'phone' => $c->phone])->all(),
                'sites' => $sites->map(fn (PartySite $x): array => ['id' => (int) $x->id, 'name' => (string) $x->name, 'address_line1' => $x->address_line1, 'city' => $x->city, 'country_code' => $x->country_code])->all(),
            ];
        });
    }
}
