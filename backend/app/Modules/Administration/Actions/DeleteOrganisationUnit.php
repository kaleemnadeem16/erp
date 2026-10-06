<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/** Deletes an empty unit only. A unit with sub-units, people or resources is archived instead, so access never changes by accident. */
final class DeleteOrganisationUnit
{
    public function __invoke(OrganisationUnit $unit, User $actor): void
    {
        ActionAuthorization::require($actor, 'administration.organisation.manage', 'manage organisation units');

        DB::transaction(function () use ($unit): void {
            $unit = OrganisationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            if ($unit->children()->exists() || $unit->members()->exists() || $unit->resources()->exists()) {
                throw new DomainInvariantException('This unit still has sub-units, people or resources. Move or remove them, or archive the unit instead.', 'unit');
            }
            $unit->delete();
        });
    }
}
