<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\SaveOrganisationUnit;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Actions\SyncOrganisationUnitMembers;
use App\Modules\Administration\Actions\SyncOrganisationUnitResources;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Models\OrganisationUnitResource;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\Demo\Concerns\CreatesDemoRolePersonas;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Organisation units and record scope (ADR 0089, Q26) the way an administrator would set them up, through the same Actions the screens
 * call: a tree (Head office > Main site, Karachi site), each site owning one of the demo warehouses, and a "Warehouse clerk" role given
 * to two people with the role limited to "only my units". Each clerk then reaches only their own site's stock, ledger and counts; the
 * demo administrator and every other persona keep company-wide access, so nothing else in the demo changes. Create-once: an existing
 * unit, binding, membership or limit is left as it is, so a rerun never undoes a change made in the screen.
 */
final class OrganisationDemoSeeder extends Seeder
{
    use CreatesDemoRolePersonas;
    use EnsuresDemoEnvironment;

    public const CLERK_ROLE = 'Warehouse clerk';

    public const MAIN_CLERK = 'demo-clerk-main@erp.x1techs.com';

    public const KARACHI_CLERK = 'demo-clerk-khi@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $hq = $this->unit($admin, 'hq', 'Head office');
        $main = $this->unit($admin, 'main', 'Main site', $hq);
        $karachi = $this->unit($admin, 'khi', 'Karachi site', $hq);
        $this->own($admin, $main, 'demo-main');
        $this->own($admin, $karachi, 'demo-khi');

        $permissions = ['inventory.stock.view', 'inventory.movement.view', 'inventory.physical_count.create', 'inventory.physical_count.count'];
        $this->rolePersona($admin, self::CLERK_ROLE, 'Counts and looks up stock in their own site only (the role is limited to "only my units" per person).', $permissions, self::MAIN_CLERK, 'Demo Main Site Clerk');
        $this->rolePersona($admin, self::CLERK_ROLE, 'Counts and looks up stock in their own site only (the role is limited to "only my units" per person).', $permissions, self::KARACHI_CLERK, 'Demo Karachi Clerk');
        $this->place($admin, self::MAIN_CLERK, $main);
        $this->place($admin, self::KARACHI_CLERK, $karachi);
    }

    private function unit(User $admin, string $code, string $name, ?OrganisationUnit $parent = null): OrganisationUnit
    {
        return OrganisationUnit::query()->where('code', $code)->first()
            ?? app(SaveOrganisationUnit::class)(null, ['code' => $code, 'name' => $name, 'parent_id' => $parent?->id], $admin);
    }

    /** Give the unit a warehouse, unless that warehouse already belongs to some unit (an administrator's choice stands). */
    private function own(User $admin, OrganisationUnit $unit, string $warehouseCode): void
    {
        $warehouse = Warehouse::query()->where('code', $warehouseCode)->first();
        if ($warehouse === null || OrganisationUnitResource::query()->where('resource_type', 'warehouse')->where('resource_id', $warehouse->id)->exists()) {
            return;
        }
        $current = $unit->resources()->get()->map(fn ($r): array => ['type' => $r->resource_type, 'id' => (int) $r->resource_id])->all();
        app(SyncOrganisationUnitResources::class)($unit, [...$current, ['type' => 'warehouse', 'id' => $warehouse->id]], $admin);
    }

    /** Put the clerk in their unit and limit the role to it — only while the person has no limit and no unit yet. */
    private function place(User $admin, string $email, OrganisationUnit $unit): void
    {
        $clerk = User::query()->where('email', $email)->firstOrFail();
        $role = Role::query()->where('team_id', $clerk->company_id)->where('name', self::CLERK_ROLE)->firstOrFail();
        if (! $unit->members()->where('users.id', $clerk->id)->exists() && ! OrganisationUnit::query()->whereHas('members', fn ($q) => $q->where('users.id', $clerk->id))->exists()) {
            app(SyncOrganisationUnitMembers::class)($unit, [...$unit->members()->pluck('users.id')->all(), $clerk->id], $admin);
        }
        if (! AccessScopeLimit::query()->where('user_id', $clerk->id)->exists()) {
            app(SyncMemberAccess::class)($clerk, [
                'role_ids' => [$role->id], 'direct_permissions' => [],
                'scope_limits' => [['role_id' => $role->id, 'scope' => AccessScopeLimit::MY_UNITS]],
                'reason' => 'Demo: the clerk reaches only their own site.',
            ], $admin);
        }
    }
}
