<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * Bring an archived Party back instead of creating a second identity for the same person or organisation (ADR 0105). Only the identity returns:
 * its customer and supplier accounts stay archived, because each financial role is restored on its own authority (ADR 0084) — restoring a name
 * never restores the ability to buy from or sell to it. Idempotent: restoring an active Party changes nothing.
 */
final class RestoreParty
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    public function __invoke(Party $party, User $actor): Party
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE, $party->company_id);

        return DB::transaction(function () use ($party): Party {
            $locked = Party::withTrashed()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->trashed()) {
                return $locked;
            }
            // The unique (company, code) index also covers archived rows, so a restore can never collide with a newer identity.
            $locked->restore();

            return $locked->refresh();
        });
    }
}
