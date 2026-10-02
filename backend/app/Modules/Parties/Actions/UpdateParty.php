<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class UpdateParty
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Party $party, array $attributes, User $actor): Party
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE, $party->company_id);

        return DB::transaction(function () use ($party, $attributes): Party {
            $party->update($attributes);

            return $party->refresh();
        });
    }
}
