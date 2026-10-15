<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

/** Adds a contact person to a Party. A Party has at most one primary contact: making this one primary demotes the previous one. */
final class CreatePartyContact
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Party $party, array $attributes, User $actor): PartyContact
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE, $party->company_id);

        return DB::transaction(function () use ($party, $attributes): PartyContact {
            $primary = (bool) ($attributes['is_primary'] ?? false);
            if ($primary) {
                $party->contacts()->where('is_primary', true)->update(['is_primary' => false]);
            }

            return $party->contacts()->create([
                'name' => trim((string) $attributes['name']),
                'title' => $attributes['title'] ?? null,
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'is_primary' => $primary,
            ])->refresh();
        });
    }
}
