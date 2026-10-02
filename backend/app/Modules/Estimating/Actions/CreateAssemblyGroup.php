<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\AssemblyGroup;
use Illuminate\Support\Facades\DB;

final class CreateAssemblyGroup
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): AssemblyGroup
    {
        if (! $actor->can('estimating.assembly-group.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assembly groups.');
        }

        return DB::transaction(fn () => AssemblyGroup::create($attributes)->refresh());
    }
}
