<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\Party;
use Illuminate\Support\Facades\DB;

final class CreateParty
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Party
    {
        return DB::transaction(function () use ($attributes): Party {
            $party = Party::create($attributes);

            return $party->refresh();
        });
    }
}
