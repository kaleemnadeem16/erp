<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Kernel\ActiveActor;

/**
 * The single place that decides whether a quotation list may show a recipient Party's name. Party read has no duty and no record scope today (the Parties API is open to every
 * active member of the company, and the company scope keeps another company's Party out), so the rule is: a current ACTIVE member, and a Party visible under the company scope.
 * A Party that is not visible is simply missing from the answer, never "forbidden". Callers hold the enclosing transaction.
 */
final class RecipientNames
{
    /**
     * @param  list<int>  $partyIds
     * @return array<int, string> party id => name, for the Parties the actor may read
     */
    public function for(User $actor, array $partyIds): array
    {
        ActiveActor::require($actor, 'see recipient names');
        if ($partyIds === []) {
            return [];
        }

        return Party::query()->whereIn('id', $partyIds)->pluck('name', 'id')->map(fn ($name): string => (string) $name)->all();
    }
}
