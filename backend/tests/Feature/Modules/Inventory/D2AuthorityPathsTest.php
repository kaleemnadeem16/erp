<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Inventory\Actions\DeleteWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Owner decision D2: the complete authorization path (HTTP Request and direct Action) of item-package maintenance and location-role removal. */
class D2AuthorityPathsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.item.manage');
        Permission::findOrCreate('inventory.location.manage');
    }

    private function role(): WarehouseLocationAssignment
    {
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $location = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        return WarehouseLocationAssignment::create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'role' => 'RECEIVING']);
    }

    private function item(): Item
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => 'VOLUME', 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $account = ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => 'ASSET']);

        return Item::create(['code' => 'i1', 'name' => 'i1', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id, 'is_stocked' => true,
            'valuation_method' => 'AVCO', 'avco_pool_scope' => 'COMPANY_ITEM', 'inventory_account_id' => $account->id]);
    }

    private function user(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_http_role_removal_needs_the_permission(): void
    {
        $role = $this->role();
        $this->actingAs(User::factory()->create())->deleteJson("/api/v1/warehouse-location-roles/{$role->id}")->assertForbidden();
        $this->assertDatabaseHas('warehouse_location_roles', ['id' => $role->id]);
        $this->actingAs($this->user('inventory.location.manage'))->deleteJson("/api/v1/warehouse-location-roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseMissing('warehouse_location_roles', ['id' => $role->id]);
    }

    public function test_http_role_removal_by_a_suspended_member_changes_nothing(): void
    {
        $role = $this->role();
        $user = $this->user('inventory.location.manage');
        $user->forceFill(['status' => MemberStatus::Suspended->value])->save();
        $this->actingAs($user)->deleteJson("/api/v1/warehouse-location-roles/{$role->id}");
        $this->assertDatabaseHas('warehouse_location_roles', ['id' => $role->id]);
    }

    public function test_direct_role_removal_by_a_stale_actor_whose_permission_was_revoked_is_refused(): void
    {
        $role = $this->role();
        $held = $this->user('inventory.location.manage');
        $held->hasPermissionTo('inventory.location.manage'); // permissions cached on the held model
        $held->revokePermissionTo('inventory.location.manage');
        $this->expectException(DomainInvariantException::class);
        try {
            app(DeleteWarehouseLocationAssignment::class)($role, $held);
        } finally {
            $this->assertDatabaseHas('warehouse_location_roles', ['id' => $role->id]);
        }
    }

    public function test_direct_role_removal_by_a_suspended_actor_is_refused_at_decision_time(): void
    {
        $role = $this->role();
        $held = $this->user('inventory.location.manage');
        $this->assertTrue($held->hasPermissionTo('inventory.location.manage'));
        User::query()->whereKey($held->id)->update(['status' => MemberStatus::Suspended->value]);
        $this->expectException(DomainInvariantException::class);
        try {
            app(DeleteWarehouseLocationAssignment::class)($role, $held);
        } finally {
            $this->assertDatabaseHas('warehouse_location_roles', ['id' => $role->id]);
        }
    }

    public function test_http_package_writes_need_the_permission_and_refuse_a_suspended_member(): void
    {
        $item = $this->item();
        $body = ['item_id' => $item->id, 'code' => 'box', 'name' => 'Box', 'stock_quantity' => '6'];
        $this->actingAs(User::factory()->create())->postJson('/api/v1/item-packagings', $body)->assertForbidden();
        $suspended = $this->user('inventory.item.manage');
        $suspended->forceFill(['status' => MemberStatus::Suspended->value])->save();
        $this->actingAs($suspended)->postJson('/api/v1/item-packagings', $body);
        $this->assertSame(0, ItemPackaging::query()->count());
        $this->actingAs($this->user('inventory.item.manage'))->postJson('/api/v1/item-packagings', $body)->assertCreated();
        $id = ItemPackaging::query()->firstOrFail()->id;
        $this->actingAs(User::factory()->create())->patchJson("/api/v1/item-packagings/{$id}", ['name' => 'X'])->assertForbidden();
        $this->assertSame('Box', ItemPackaging::query()->findOrFail($id)->name);
    }

    public function test_direct_package_write_by_a_suspended_or_stripped_actor_is_refused(): void
    {
        $item = $this->item();
        $held = $this->user('inventory.item.manage');
        $this->assertTrue($held->hasPermissionTo('inventory.item.manage'));
        User::query()->whereKey($held->id)->update(['status' => MemberStatus::Suspended->value]);
        try {
            app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'box', 'name' => 'Box', 'stock_quantity' => '6'], $held);
            $this->fail('a suspended actor created a package');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('not permitted', $e->getMessage());
        }
        $this->assertSame(0, ItemPackaging::query()->count());
    }
}
