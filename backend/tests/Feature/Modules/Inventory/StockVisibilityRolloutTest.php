<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * The release that starts enforcing the three stock-read capabilities (ADR 0083) first grants them to everyone who already used the
 * Inventory screens, so nobody legitimate is locked out — and deliberately not to a pure counter, who counts blind.
 */
class StockVisibilityRolloutTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = ['inventory.availability.view', 'inventory.stock.view', 'inventory.movement.view'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_03_160000_grant_stock_visibility_to_existing_screen_holders.php');
    }

    /** @return list<string> */
    private function roleCapabilities(Role $role): array
    {
        return $role->fresh()->permissions->pluck('name')->intersect(self::NEW)->sort()->values()->all();
    }

    public function test_existing_screen_holders_keep_access_and_a_pure_counter_does_not_gain_it(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.item.manage', 'inventory.valuation.view', 'inventory.physical_count.count', 'purchasing.purchase-order.view'] as $name) {
            Permission::findOrCreate($name);
        }
        $warehouse = Role::query()->create(['team_id' => 1, 'name' => 'Warehouse']);
        $warehouse->givePermissionTo('inventory.item.manage');
        $finance = Role::query()->create(['team_id' => 1, 'name' => 'Finance']);
        $finance->givePermissionTo('inventory.valuation.view');
        $counter = Role::query()->create(['team_id' => 1, 'name' => 'Counter']);
        $counter->givePermissionTo('inventory.physical_count.count');
        $buyer = Role::query()->create(['team_id' => 1, 'name' => 'Buyer']);
        $buyer->givePermissionTo('purchasing.purchase-order.view');
        $directHolder = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $directCounter = PermissionedActor::grant(User::factory()->create(), ['inventory.physical_count.count']);
        $mixed = PermissionedActor::grant(User::factory()->create(), ['inventory.physical_count.count', 'inventory.valuation.view']);

        $this->migration()->up();
        $this->migration()->up(); // idempotent: running it twice adds nothing more

        $this->assertSame(['inventory.availability.view', 'inventory.movement.view', 'inventory.stock.view'], $this->roleCapabilities($warehouse));
        $this->assertSame(['inventory.availability.view', 'inventory.movement.view', 'inventory.stock.view'], $this->roleCapabilities($finance));
        $this->assertSame([], $this->roleCapabilities($counter), 'a count-only role keeps counting blind');
        $this->assertSame([], $this->roleCapabilities($buyer), 'no inventory duty, no stock reads');

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($directHolder->fresh()->hasAllPermissions(self::NEW));
        $this->assertTrue($mixed->fresh()->hasAllPermissions(self::NEW), 'a counter who also holds another inventory duty is granted through it');
        $this->assertSame([], array_values(array_filter(self::NEW, fn ($p) => $directCounter->fresh()->hasPermissionTo($p))));
        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $counter->id)->whereIn('permission_id', Permission::query()->whereIn('name', self::NEW)->pluck('id'))->count());
    }

    public function test_it_follows_holders_company_by_company_and_invents_nothing(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.item.manage');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $other = Role::query()->create(['team_id' => 2, 'name' => 'Other warehouse']);
        $other->givePermissionTo('inventory.item.manage');

        $this->migration()->up();

        // It follows the holders, company by company: company 2's role held a trigger, so it is granted — and no company-1 role was invented.
        $this->assertSame(['inventory.availability.view', 'inventory.movement.view', 'inventory.stock.view'], $this->roleCapabilities($other));
        $this->assertSame(0, Role::query()->where('team_id', 1)->whereHas('permissions', fn ($q) => $q->whereIn('name', self::NEW))->count());
    }
}
