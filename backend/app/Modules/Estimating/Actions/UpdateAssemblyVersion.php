<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Only a DRAFT version may have its components replaced — once ACTIVE or
 * SUPERSEDED it is frozen, see docs/domain/assemblies.md "Versioning".
 * `notes` is metadata and updatable regardless. Mirrors
 * UpdateFormulationVersion.
 */
final class UpdateAssemblyVersion
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(AssemblyVersion $version, array $attributes, User $actor): AssemblyVersion
    {
        if (! $actor->can('estimating.assembly.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assemblies.');
        }

        $components = array_key_exists('components', $attributes) ? $attributes['components'] : null;
        unset($attributes['components']);

        if ($components !== null && $version->status !== AssemblyVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT assembly version may have its components changed; create a new version instead.',
                field: 'components',
            );
        }

        return DB::transaction(function () use ($version, $attributes, $components): AssemblyVersion {
            if ($components !== null) {
                $version->components()->delete();
                foreach ($components as $sequence => $component) {
                    $version->components()->create([...$component, 'sequence' => $component['sequence'] ?? $sequence]);
                }
            }

            $version->update($attributes);

            return $version->refresh()->load('components');
        });
    }
}
