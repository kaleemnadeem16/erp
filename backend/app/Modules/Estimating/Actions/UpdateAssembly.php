<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use Illuminate\Support\Facades\DB;

final class UpdateAssembly
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Assembly $assembly, array $attributes, User $actor): Assembly
    {
        if (! $actor->can('estimating.assembly.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assemblies.');
        }

        return DB::transaction(function () use ($assembly, $attributes): Assembly {
            $assembly->update($attributes);

            return $assembly->refresh();
        });
    }
}
