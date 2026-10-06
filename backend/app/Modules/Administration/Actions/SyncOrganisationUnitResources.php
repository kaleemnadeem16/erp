<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Models\OrganisationUnitResource;
use App\Support\ActionAuthorization;
use App\Support\Scope\ScopedResourceTypes;
use Illuminate\Support\Facades\DB;

/**
 * Sets which resources (today warehouses) a unit owns. A resource belongs to at most one unit; one that belongs to none is reached only
 * by unrestricted people, so binding is what gives a limited person their warehouses (ADR 0089).
 */
final class SyncOrganisationUnitResources
{
    public function __construct(private readonly ScopedResourceTypes $types) {}

    /** @param  list<array{type: string, id: int}>  $resources */
    public function __invoke(OrganisationUnit $unit, array $resources, User $actor): OrganisationUnit
    {
        ActionAuthorization::require($actor, 'administration.organisation.manage', 'manage organisation units');

        return DB::transaction(function () use ($unit, $resources, $actor): OrganisationUnit {
            $unit = OrganisationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $wanted = collect($resources)->map(fn (array $r): array => ['type' => (string) $r['type'], 'id' => (int) $r['id']])->unique(fn (array $r): string => $r['type'].':'.$r['id'])->values();
            foreach ($wanted as $resource) {
                if (! $this->types->has($resource['type'])) {
                    throw new DomainInvariantException("'{$resource['type']}' is not a kind of resource a unit can own.", 'resources');
                }
                if (! $this->types->exists($resource['type'], $resource['id'])) {
                    throw new DomainInvariantException("{$this->types->label($resource['type'])} {$resource['id']} does not exist in this company.", 'resources');
                }
                $owner = OrganisationUnitResource::query()->where('resource_type', $resource['type'])->where('resource_id', $resource['id'])->where('unit_id', '!=', $unit->id)->first();
                if ($owner !== null) {
                    $name = OrganisationUnit::query()->whereKey($owner->unit_id)->value('name');
                    throw new DomainInvariantException("{$this->types->label($resource['type'])} {$resource['id']} already belongs to the unit '{$name}'. Remove it there first.", 'resources');
                }
            }
            $before = $unit->resources()->get()->map(fn ($r): string => $r->resource_type.':'.$r->resource_id)->sort()->values()->all();
            $unit->resources()->get()->each(function (OrganisationUnitResource $row) use ($wanted): void {
                if (! $wanted->contains(fn (array $r): bool => $r['type'] === $row->resource_type && $r['id'] === $row->resource_id)) {
                    $row->delete();
                }
            });
            foreach ($wanted as $resource) {
                OrganisationUnitResource::query()->firstOrCreate(['unit_id' => $unit->id, 'resource_type' => $resource['type'], 'resource_id' => $resource['id']]);
            }
            $after = $wanted->map(fn (array $r): string => $r['type'].':'.$r['id'])->sort()->values()->all();
            if ($before !== $after) {
                activity()->performedOn($unit)->causedBy($actor)->withProperties(['before' => ['resources' => $before], 'after' => ['resources' => $after]])->log('unit resources changed');
            }

            return $unit->refresh();
        });
    }
}
