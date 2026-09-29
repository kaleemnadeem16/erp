<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Actions\QueryStockAvailability;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\InventoryScope;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StructuredLocationsDemoSeeder claims a typed floor, warehouse roles, two governed marks and pigment placed
 * so the Material Preview shows every way a location can count. Each claim is proved through the real
 * availability read and the real settings action, not by reading the seeded rows back.
 */
final class StructuredLocationsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_the_company_is_structured_and_the_main_warehouse_has_a_hierarchy_of_typed_locations(): void
    {
        $this->assertSame(InventoryLocationMode::Structured, Company::query()->findOrFail(1)->inventory_location_mode);

        $main = $this->main();
        $zone = $main->locations()->where('code', 'zone-a')->sole();
        $this->assertSame(LocationType::View, $zone->type);
        $this->assertFalse($zone->allows_stock);
        $this->assertEqualsCanonicalizing(['bin-a1', 'bin-a2'], $main->locations()->where('parent_id', $zone->id)->pluck('code')->all());
        foreach (['recv' => 'RECEIVING', 'quar' => 'QUARANTINE', 'returns' => 'RETURNS', 'dispatch' => 'DISPATCH', 'scrap' => 'SCRAP', 'wip' => 'PRODUCTION', 'transit' => 'TRANSIT'] as $code => $type) {
            $this->assertSame($type, $main->locations()->where('code', $code)->sole()->type->value);
        }
        $this->assertSame(6, WarehouseLocationAssignment::query()->where('warehouse_id', $main->id)->count());
    }

    public function test_the_pigment_counts_by_type_or_by_the_companys_mark_and_the_preview_can_tell_which(): void
    {
        $pigment = Item::query()->where('code', 'demo-pig-blue')->sole();
        $byCode = collect(app(QueryStockAvailability::class)([$pigment->id], new InventoryScope)->balancesFor($pigment->id))
            ->filter(fn (StockBalance $b): bool => $b->warehouseCode === 'demo-main')
            ->keyBy(fn (StockBalance $b): string => $b->locationCode);

        // By type: the dock counts, the quarantine cage does not.
        $this->assertTrue($byCode['recv']->locationUsable);
        $this->assertNull($byCode['recv']->locationMarked);
        $this->assertFalse($byCode['quar']->locationUsable);
        $this->assertNull($byCode['quar']->locationMarked);
        // By the company's mark, overriding the type either way.
        $this->assertTrue($byCode['returns']->locationUsable);
        $this->assertTrue($byCode['returns']->locationMarked);
        $this->assertFalse($byCode['overflow']->locationUsable);
        $this->assertFalse($byCode['overflow']->locationMarked);
        $this->assertSame('6.0000', (string) $byCode['returns']->usable);
        $this->assertSame('4.0000', (string) $byCode['overflow']->unusableLocation);
    }

    public function test_both_marks_are_in_the_policy_history_with_their_reasons(): void
    {
        $changes = InventoryPolicyChange::query()->where('policy', 'location_usability')->get();

        $this->assertCount(2, $changes);
        $this->assertTrue($changes->every(fn (InventoryPolicyChange $c): bool => trim($c->reason) !== ''));
        $this->assertNotNull(InventoryPolicyChange::query()->where('policy', 'LOCATION_STRUCTURE')->first());
    }

    public function test_structured_mode_cannot_be_undone_now_that_locations_carry_structure(): void
    {
        $this->expectException(DomainInvariantException::class);

        app(UpdateInventorySettings::class)(['inventory_location_mode' => 'SIMPLE'], 1, 'Try to undo');
    }

    public function test_reseeding_changes_nothing(): void
    {
        $locations = Location::query()->count();
        $moves = StockMove::query()->count();
        $history = InventoryPolicyChange::query()->count();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($locations, Location::query()->count());
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame($history, InventoryPolicyChange::query()->count());
    }

    private function main(): Warehouse
    {
        return Warehouse::query()->where('code', 'demo-main')->sole();
    }
}
