<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Creates or changes one unit of the company's own organisation tree (ADR 0089). The tree stays a tree: a unit cannot be its own
 * ancestor, and a parent must be a unit of this company. Changes are written to the activity log by the model.
 */
final class SaveOrganisationUnit
{
    /** @param  array<string, mixed>  $attributes  code, name, parent_id (nullable), status */
    public function __invoke(?OrganisationUnit $unit, array $attributes, User $actor): OrganisationUnit
    {
        ActionAuthorization::require($actor, 'administration.organisation.manage', 'manage organisation units');

        return DB::transaction(function () use ($unit, $attributes): OrganisationUnit {
            $parentId = array_key_exists('parent_id', $attributes) ? $attributes['parent_id'] : $unit?->parent_id;
            if ($parentId !== null) {
                // The company scope on the model means a unit of another company simply is not found.
                $parent = OrganisationUnit::query()->lockForUpdate()->find($parentId)
                    ?? throw new DomainInvariantException('The parent unit does not exist in this company.', 'parent_id');
                if ($unit !== null && ($parent->id === $unit->id || in_array($parent->id, $this->descendantIds($unit->id), true))) {
                    throw new DomainInvariantException('A unit cannot be moved under itself or one of its own sub-units.', 'parent_id');
                }
                if ($parent->status === OrganisationUnit::ARCHIVED && ($unit === null || $unit->parent_id !== $parent->id)) {
                    throw new DomainInvariantException('An archived unit cannot receive new sub-units.', 'parent_id');
                }
            }
            if (OrganisationUnit::query()->where('code', strtolower((string) ($attributes['code'] ?? $unit?->code)))->when($unit, fn ($q) => $q->whereKeyNot($unit->id))->exists()) {
                throw new DomainInvariantException('Another unit already uses that code.', 'code');
            }

            $unit ??= new OrganisationUnit;
            $unit->fill([
                'code' => $attributes['code'] ?? $unit->code,
                'name' => $attributes['name'] ?? $unit->name,
                'parent_id' => $parentId,
                'status' => $attributes['status'] ?? ($unit->status ?? OrganisationUnit::ACTIVE),
            ])->save();

            return $unit->refresh();
        });
    }

    /** @return list<int> */
    private function descendantIds(int $unitId): array
    {
        return array_map(fn ($row): int => (int) $row->id, DB::select(
            'WITH RECURSIVE sub AS (
                SELECT id FROM organisation_units WHERE parent_id = ?
              UNION
                SELECT c.id FROM organisation_units c JOIN sub s ON c.parent_id = s.id
            ) SELECT id FROM sub',
            [$unitId],
        ));
    }
}
