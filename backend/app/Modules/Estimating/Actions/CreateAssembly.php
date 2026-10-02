<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use Illuminate\Support\Facades\DB;

final class CreateAssembly
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Assembly
    {
        if (! $actor->can('estimating.assembly.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assemblies.');
        }

        return DB::transaction(fn () => Assembly::create($attributes)->refresh());
    }
}
