<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class CreateParty
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Party
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE);

        return DB::transaction(function () use ($attributes): Party {
            $party = Party::create($attributes);

            return $party->refresh();
        });
    }
}
