<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

/** Changes a contact person's details. Making one primary demotes the Party's previous primary contact. */
final class UpdatePartyContact
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(PartyContact $contact, array $attributes, User $actor): PartyContact
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE, $contact->party->company_id);

        return DB::transaction(function () use ($contact, $attributes): PartyContact {
            $changes = array_intersect_key($attributes, array_flip(['name', 'title', 'email', 'phone', 'is_primary']));
            if (($changes['is_primary'] ?? false) === true) {
                $contact->party->contacts()->whereKeyNot($contact->id)->where('is_primary', true)->update(['is_primary' => false]);
            }
            if (isset($changes['name'])) {
                $changes['name'] = trim((string) $changes['name']);
            }
            $contact->update($changes);

            return $contact->refresh();
        });
    }
}
