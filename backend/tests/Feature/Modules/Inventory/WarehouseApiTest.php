<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_warehouse_atomically_creates_its_first_location(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/warehouses', [
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'RECEIVING', 'location_name' => 'Receiving',
        ])->assertCreated()->assertJsonPath('data.code', 'main')->assertJsonCount(1, 'data.locations');
        $this->assertDatabaseHas('locations', ['code' => 'receiving']);
    }

    public function test_only_one_default_warehouse_is_allowed(): void
    {
        $user = User::factory()->create();
        Warehouse::create(['code' => 'one', 'name' => 'One', 'is_default' => true])->locations()->create(['code' => 'main', 'name' => 'Main']);
        $this->actingAs($user)->postJson('/api/v1/warehouses', [
            'code' => 'two', 'name' => 'Two', 'is_default' => true,
            'location_code' => 'main', 'location_name' => 'Main',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('warehouses', ['code' => 'two']);
    }

    public function test_last_active_location_cannot_be_archived(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $location = $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);
        $this->actingAs($user)->deleteJson("/api/v1/locations/{$location->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('active');
    }

    public function test_archived_warehouse_cannot_be_reactivated_without_an_active_location(): void
    {
        $user = User::factory()->create();
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
}
