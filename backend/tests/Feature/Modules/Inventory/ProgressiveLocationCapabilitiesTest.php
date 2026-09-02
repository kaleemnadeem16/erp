<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\InventoryLocationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressiveLocationCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_mode_is_the_default_and_hides_structured_writes(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $this->actingAs($user)->getJson('/api/v1/inventory-settings')
            ->assertOk()->assertJsonPath('data.inventory_location_mode', 'SIMPLE');

        $this->postJson('/api/v1/locations', [
            'warehouse_id' => $warehouse->id, 'code' => 'bin-1', 'name' => 'Bin 1',
        ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_structured_mode_supports_hierarchy_metadata_and_operational_roles(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $root = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
        ])->assertOk()->assertJsonPath('data.inventory_location_mode', 'STRUCTURED');

        $response = $this->postJson('/api/v1/locations', [
            'warehouse_id' => $warehouse->id,
            'parent_id' => $root->id,
            'code' => 'recv',
            'name' => 'Receiving dock',
            'type' => 'RECEIVING',
            'barcode' => 'DOCK-001',
            'sort_order' => 10,
            'allows_stock' => true,
        ])->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.type', 'RECEIVING')
            ->assertJsonPath('data.barcode', 'DOCK-001');

        $locationId = $response->json('data.id');
        $this->postJson('/api/v1/warehouse-location-roles', [
            'warehouse_id' => $warehouse->id,
            'location_id' => $locationId,
            'role' => 'RECEIVING',
        ])->assertCreated()->assertJsonPath('data.role', 'RECEIVING');
    }

    public function test_structured_integrity_and_safe_downgrade_are_enforced(): void
    {
        $user = User::factory()->create();
        Company::query()->findOrFail(1)->update(['inventory_location_mode' => InventoryLocationMode::Structured]);
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $root = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $this->actingAs($user)->postJson('/api/v1/locations', [
            'warehouse_id' => $warehouse->id,
            'parent_id' => $root->id,
            'code' => 'view',
            'name' => 'View',
            'type' => 'VIEW',
            'allows_stock' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('allows_stock');

        $this->postJson('/api/v1/locations', [
            'warehouse_id' => $warehouse->id, 'code' => 'bin-1', 'name' => 'Bin 1',
        ])->assertCreated();

        $this->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'SIMPLE',
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_location_mode');
    }

    public function test_downgrade_succeeds_when_each_warehouse_only_has_its_plain_location(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->findOrFail(1);
        $company->update(['inventory_location_mode' => InventoryLocationMode::Structured]);
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'SIMPLE',
        ])->assertOk()->assertJsonPath('data.inventory_location_mode', 'SIMPLE');
    }

    public function test_parent_and_barcode_are_scoped_to_the_warehouse(): void
    {
        $user = User::factory()->create();
        Company::query()->findOrFail(1)->update(['inventory_location_mode' => InventoryLocationMode::Structured]);
        $one = Warehouse::create(['code' => 'one', 'name' => 'One']);
        $two = Warehouse::create(['code' => 'two', 'name' => 'Two']);
        $oneRoot = $one->locations()->create(['code' => 'main', 'name' => 'Main', 'barcode' => 'SHARED']);
        $twoRoot = $two->locations()->create(['code' => 'main', 'name' => 'Main', 'barcode' => 'SHARED']);

        $this->actingAs($user)->postJson('/api/v1/locations', [
            'warehouse_id' => $one->id, 'parent_id' => $twoRoot->id, 'code' => 'bad', 'name' => 'Bad',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->postJson('/api/v1/locations', [
            'warehouse_id' => $one->id, 'code' => 'duplicate', 'name' => 'Duplicate', 'barcode' => 'SHARED',
        ])->assertUnprocessable()->assertJsonValidationErrors('barcode');

        $this->assertNotSame($oneRoot->warehouse_id, $twoRoot->warehouse_id);
    }

    public function test_location_role_queries_validate_filters_sort_includes_and_pagination(): void
    {
        $user = User::factory()->create();
        Company::query()->findOrFail(1)->update(['inventory_location_mode' => InventoryLocationMode::Structured]);
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $location = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $roleId = $this->actingAs($user)->postJson('/api/v1/warehouse-location-roles', [
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'role' => 'RECEIVING',
        ])->assertCreated()->assertJsonMissingPath('data.warehouse')->json('data.id');

        $this->getJson('/api/v1/warehouse-location-roles?filter[warehouse_id]='.$warehouse->id.'&sort=-created_at&include=warehouse,location&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.warehouse.id', $warehouse->id)
            ->assertJsonPath('data.0.location.id', $location->id);

        $this->getJson('/api/v1/warehouse-location-roles?sort=name')->assertUnprocessable();
        $this->getJson('/api/v1/warehouse-location-roles?include=unknown')->assertUnprocessable();
        $this->getJson("/api/v1/warehouse-location-roles/{$roleId}?include=unknown")->assertUnprocessable();
    }
}
