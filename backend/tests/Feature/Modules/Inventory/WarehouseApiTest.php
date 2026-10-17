<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.warehouse.manage');
        Permission::findOrCreate('inventory.location.manage');
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.warehouse.manage', 'inventory.location.manage');

        return $user;
    }

    public function test_warehouse_and_role_writes_need_their_permissions_but_reads_stay_open(): void
    {
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $location = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->getJson('/api/v1/warehouses')->assertOk();
        $this->getJson("/api/v1/warehouses/{$warehouse->id}")->assertOk();
        $this->postJson('/api/v1/warehouses', ['code' => 'two', 'name' => 'Two', 'location_code' => 'main', 'location_name' => 'Main'])->assertForbidden();
        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}")->assertForbidden();
        $this->postJson('/api/v1/warehouse-location-roles', ['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'role' => 'RECEIVING'])->assertForbidden();
        $this->assertSame('Main', $warehouse->fresh()->name);

        // Location maintenance alone is not warehouse maintenance.
        $locationManager = User::factory()->create();
        $locationManager->givePermissionTo('inventory.location.manage');
        $this->actingAs($locationManager)->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Renamed'])->assertForbidden();

        $this->actingAs($this->manager())->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_creating_a_warehouse_atomically_creates_its_first_location(): void
    {
        $user = $this->manager();
        $this->actingAs($user)->postJson('/api/v1/warehouses', [
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'RECEIVING', 'location_name' => 'Receiving',
        ])->assertCreated()->assertJsonPath('data.code', 'main')->assertJsonCount(1, 'data.locations');
        $this->assertDatabaseHas('locations', ['code' => 'receiving']);
    }

    public function test_only_one_default_warehouse_is_allowed(): void
    {
        $user = $this->manager();
        Warehouse::create(['code' => 'one', 'name' => 'One', 'is_default' => true])->locations()->create(['code' => 'main', 'name' => 'Main']);
        $this->actingAs($user)->postJson('/api/v1/warehouses', [
            'code' => 'two', 'name' => 'Two', 'is_default' => true,
            'location_code' => 'main', 'location_name' => 'Main',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('warehouses', ['code' => 'two']);
    }

    public function test_last_active_location_cannot_be_archived(): void
    {
        $user = $this->manager();
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $location = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);
        $this->actingAs($user)->deleteJson("/api/v1/locations/{$location->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('active');
    }

    public function test_archived_warehouse_cannot_be_reactivated_without_an_active_location(): void
    {
        $user = $this->manager();
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);

        $this->actingAs($user)->deleteJson("/api/v1/warehouses/{$warehouse->id}")->assertNoContent();
        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('active');
    }

    public function test_location_codes_are_unique_within_a_warehouse_only(): void
    {
        $one = Warehouse::create(['code' => 'one', 'name' => 'One']);
        $two = Warehouse::create(['code' => 'two', 'name' => 'Two']);
        Location::create(['warehouse_id' => $one->id, 'code' => 'main', 'name' => 'Main']);
        Location::create(['warehouse_id' => $two->id, 'code' => 'main', 'name' => 'Main']);
        $this->assertSame(2, Location::query()->count());
    }

    public function test_location_cannot_reference_another_companys_warehouse(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompany) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherWarehouse = Warehouse::create(['code' => 'other', 'name' => 'Other']);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $this->expectException(RuntimeException::class);
        Location::create(['warehouse_id' => $otherWarehouse->id, 'code' => 'main', 'name' => 'Main']);
    }

    public function test_index_can_filter_by_is_default_and_has_locations(): void
    {
        $defaultWh = Warehouse::create(['code' => 'wh-def', 'name' => 'Default Hub', 'is_default' => true, 'active' => true]);
        $defaultWh->locations()->create(['code' => 'loc-1', 'name' => 'Bay 1']);

        $secondaryWh = Warehouse::create(['code' => 'wh-sec', 'name' => 'Secondary Hub', 'is_default' => false, 'active' => true]);
        // secondaryWh has no locations

        $manager = $this->manager();

        // Filter default only
        $resDefault = $this->actingAs($manager)->getJson('/api/v1/warehouses?filter[is_default]=true')->assertOk();
        $this->assertSame([$defaultWh->id], collect($resDefault->json('data'))->pluck('id')->all());
        $this->assertSame(1, $resDefault->json('data.0.locations_count'));

        // Filter non-default
        $resNonDefault = $this->getJson('/api/v1/warehouses?filter[is_default]=false')->assertOk();
        $this->assertContains($secondaryWh->id, collect($resNonDefault->json('data'))->pluck('id')->all());
        $this->assertNotContains($defaultWh->id, collect($resNonDefault->json('data'))->pluck('id')->all());

        // Filter with locations
        $resWithLoc = $this->getJson('/api/v1/warehouses?filter[has_locations]=true')->assertOk();
        $this->assertContains($defaultWh->id, collect($resWithLoc->json('data'))->pluck('id')->all());
        $this->assertNotContains($secondaryWh->id, collect($resWithLoc->json('data'))->pluck('id')->all());

        // Filter without locations
        $resNoLoc = $this->getJson('/api/v1/warehouses?filter[has_locations]=false')->assertOk();
        $this->assertContains($secondaryWh->id, collect($resNoLoc->json('data'))->pluck('id')->all());
        $this->assertNotContains($defaultWh->id, collect($resNoLoc->json('data'))->pluck('id')->all());
    }

    public function test_index_rejects_invalid_filters_and_sorts(): void
    {
        $this->actingAs($this->manager())->getJson('/api/v1/warehouses?filter[unknown]=val')->assertUnprocessable();
        $this->getJson('/api/v1/warehouses?sort=unsupported_col')->assertUnprocessable();
    }
}
