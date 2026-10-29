<?php

declare(strict_types=1);

namespace App\Support\Scope;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The one place that decides which records a person may reach for a capability (ADR 0089, Q26).
 *
 * Grants are additive, as they always were. A grant is a role given to the person or a direct permission; it is company-wide unless an
 * administrator put an "only my units" limit on that assignment (`access_scope_limits`). So:
 *
 *  - if any grant that confers the capability is unrestricted, the person reaches everything (**all**), which is also what every
 *    existing assignment does, so nothing changes for anyone until a limit is set;
 *  - if no grant confers it, they reach nothing (**none**);
 *  - otherwise they reach the records of the units they belong to, with those units' descendants.
 *
 * Several capabilities can be asked at once ("any of these"): the widest wins. This class only *answers*; applying the answer to a query
 * or a write is done explicitly where the data leaves or enters the system, never as a global model scope, because internal workflows
 * (costing, posting) must read what they need whoever triggered them.
 */
final class RecordScope
{
    /** @param string|list<string> $permissions any one of them confers access */
    public function resolve(?User $user, string|array $permissions): ResolvedScope
    {
        if ($user === null) {
            return ResolvedScope::none();
        }
        $grants = $this->conferringGrants($user, (array) $permissions);
        foreach ($grants as $grant) {
            if ($grant['scope'] === null) {
                return ResolvedScope::all();
            }
        }
        if ($grants === []) {
            return ResolvedScope::none();
        }
        foreach ($grants as $grant) {
            if ($grant['scope'] !== 'MY_UNITS') {
                return ResolvedScope::none();
            }
        }

        return ResolvedScope::units($this->memberUnitIds($user));
    }

    /** Exact-duty resource resolution. Unsupported limited variants never widen to units or company-wide. */
    public function resolveForResource(?User $user, string $duty, string $resource): ResolvedScope
    {
        $adapters = app(ResourceScopeAdapters::class);
        if ($user === null || (int) $user->company_id !== app(CompanyContext::class)->id() || ! $adapters->supports($resource, $duty, 'ALL')) {
            return ResolvedScope::none();
        }
        $grants = $this->conferringGrants($user, [$duty]);
        foreach ($grants as $grant) {
            if ($grant['scope'] === null) {
                return ResolvedScope::all();
            }
        }
        if ($grants === []) {
            return ResolvedScope::none();
        }
        foreach ($grants as $grant) {
            if ($grant['scope'] !== 'OWN' || ! $adapters->supports($resource, $duty, 'OWN')) {
                return ResolvedScope::none();
            }
            foreach ($grant['permissions'] as $permission) {
                if (! $adapters->supports($resource, $permission, 'OWN')) {
                    return ResolvedScope::none();
                }
            }
        }

        return ResolvedScope::own();
    }

    /** @param list<string> $wanted @return list<array{scope: ?string, permissions: list<string>}> */
    private function conferringGrants(User $user, array $wanted): array
    {
        $limits = DB::table('access_scope_limits')->where('company_id', $user->company_id)->where('user_id', $user->id)->get();
        $result = [];
        foreach ($user->roles()->with('permissions:id,name')->get() as $role) {
            /** @var Role $role */
            $permissions = $role->permissions->pluck('name')->all();
            if (array_intersect($wanted, $permissions) === []) {
                continue;
            }
            $limit = $limits->first(fn ($row): bool => $row->role_id !== null && (int) $row->role_id === (int) $role->id);
            $result[] = ['scope' => $limit?->scope, 'permissions' => $permissions];
        }
        foreach ($user->getDirectPermissions()->pluck('name') as $permission) {
            if (! in_array($permission, $wanted, true)) {
                continue;
            }
            $limit = $limits->first(fn ($row): bool => $row->role_id === null && $row->permission === $permission);
            $result[] = ['scope' => $limit?->scope, 'permissions' => [$permission]];
        }

        return $result;
    }

    /**
     * The ids of one kind of resource (for example `warehouse`) the scope reaches, or null when it reaches them all. A resource owned by
     * no unit is not reached by a restricted person.
     *
     * @return list<int>|null
     */
    public function resourceIds(ResolvedScope $scope, string $resourceType): ?array
    {
        if ($scope->isAll()) {
            return null;
        }
        if ($scope->unitIds() === []) {
            return [];
        }

        return DB::table('organisation_unit_resources')
            ->where('company_id', app(CompanyContext::class)->id())
            ->where('resource_type', $resourceType)
            ->whereIn('unit_id', $scope->unitIds())
            ->pluck('resource_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The active units the person belongs to, with every active descendant: being in a site includes its warehouse teams.
     *
     * @return list<int>
     */
    public function memberUnitIds(User $user): array
    {
        $rows = DB::select(
            'WITH RECURSIVE reach AS (
                SELECT u.id FROM organisation_units u
                JOIN organisation_unit_members m ON m.unit_id = u.id AND m.company_id = u.company_id
                WHERE m.user_id = ? AND u.company_id = ? AND u.status = ?
              UNION
                SELECT c.id FROM organisation_units c JOIN reach r ON c.parent_id = r.id
                WHERE c.company_id = ? AND c.status = ?
            ) SELECT id FROM reach',
            [$user->id, $user->company_id, 'ACTIVE', $user->company_id, 'ACTIVE'],
        );

        return array_map(fn ($row): int => (int) $row->id, $rows);
    }
}
