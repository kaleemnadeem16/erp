<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\Party;
use Illuminate\Support\Facades\DB;

final class UpdateParty
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Party $party, array $attributes): Party
    {
        return DB::transaction(function () use ($party, $attributes): Party {
            $party->update($attributes);

            return $party->refresh();
        });
    }
}
