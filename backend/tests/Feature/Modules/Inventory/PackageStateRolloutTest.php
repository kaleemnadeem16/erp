<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** Grouped package tracking ships with its capabilities continued for people who already did the same duty on quantity-only stock, and nothing broader (authorization.md R5). */
class PackageStateRolloutTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = ['inventory.package-state.view', 'inventory.package-state.recount', 'inventory.package-state.reverse', 'inventory.package-state.issue', 'inventory.package-state.open', 'inventory.package-state.transfer', 'inventory.package-state.activate'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_08_140000_grant_package_state_continuity_to_existing_holders.php');
    }

    /** @return list<string> */
    private function held(Role $role): array
    {
        return $role->fresh()->permissions->pluck('name')->intersect(self::NEW)->sort()->values()->all();
    }

    public function test_it_continues_existing_duties_and_grants_nothing_new_or_one_way(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.stock.view', 'inventory.physical_count.post', 'inventory.physical_count.count', 'purchasing.goods-receipt.reverse', 'inventory.item.manage'] as $name) {
            Permission::findOrCreate($name);
        }
        $stock = Role::query()->create(['team_id' => 1, 'name' => 'Stock viewer']);
        $stock->givePermissionTo('inventory.stock.view');
        $poster = Role::query()->create(['team_id' => 1, 'name' => 'Count poster']);
        $poster->givePermissionTo('inventory.physical_count.post');
        $reverser = Role::query()->create(['team_id' => 1, 'name' => 'Receipt reverser']);
        $reverser->givePermissionTo('purchasing.goods-receipt.reverse');
        $counter = Role::query()->create(['team_id' => 1, 'name' => 'Counter']);
        $counter->givePermissionTo('inventory.physical_count.count');
        $items = Role::query()->create(['team_id' => 1, 'name' => 'Item manager']);
        $items->givePermissionTo('inventory.item.manage');
        $direct = PermissionedActor::grant(User::factory()->create(), ['inventory.physical_count.post']);

        $this->migration()->up();
        $this->migration()->up(); // idempotent

        $this->assertSame(['inventory.package-state.view'], $this->held($stock));
        $this->assertSame(['inventory.package-state.recount', 'inventory.package-state.view'], $this->held($poster));
        $this->assertSame(['inventory.package-state.reverse', 'inventory.package-state.view'], $this->held($reverser));
        $this->assertSame([], $this->held($counter), 'a count-only role keeps counting blind');
        $this->assertSame([], $this->held($items), 'activation and the new duties are never granted by inference');
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($direct->fresh()->hasAllPermissions(['inventory.package-state.view', 'inventory.package-state.recount']));
        $this->assertFalse($direct->fresh()->can('inventory.package-state.activate'));
    }
}
