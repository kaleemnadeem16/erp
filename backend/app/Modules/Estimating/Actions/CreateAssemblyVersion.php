<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use Illuminate\Support\Facades\DB;

/**
 * Creates version N+1 as DRAFT — always allowed regardless of the current
 * ACTIVE version's state, since this never changes what's live; only
 * ActivateAssemblyVersion does that. Mirrors CreateFormulationVersion.
 * Deliberately separate from CreateAssembly (Step 1), which only creates
 * the header — Assembly's header and its first version are two different
 * API calls here, unlike Formulation's (see this Action's docblock in the
 * Estimating README for why: CreateAssembly already shipped header-only in
 * Step 1 before assembly_versions existed).
 */
final class CreateAssemblyVersion
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Assembly $assembly, array $attributes, User $actor): AssemblyVersion
    {
        if (! $actor->can('estimating.assembly.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assemblies.');
        }

        $components = $attributes['components'] ?? [];
        unset($attributes['components']);

        return DB::transaction(function () use ($assembly, $attributes, $components): AssemblyVersion {
            $nextVersionNo = (int) $assembly->versions()->max('version_no') + 1;

            $version = $assembly->versions()->create([
                'version_no' => $nextVersionNo,
                'notes' => $attributes['notes'] ?? null,
            ]);

            foreach ($components as $sequence => $component) {
                $version->components()->create([...$component, 'sequence' => $component['sequence'] ?? $sequence]);
            }

            return $version->refresh()->load('components');
        });
    }
}
