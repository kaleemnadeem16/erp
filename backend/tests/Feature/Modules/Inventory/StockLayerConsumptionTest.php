<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class StockLayerConsumptionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeItem(ValuationMethod $method, ItemTracking $tracking = ItemTracking::None): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => $method->value]);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();

        return [$item, $uom, $location];
    }

    private function postReceiptAt(Item $item, Uom $uom, Location $location, string $qty, string $cost, Carbon $movedAt, int $sourceId, ?string $serialNo = null): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $uom->id,
            'moved_at' => $movedAt, 'source_type' => 'TEST_RECEIPT', 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => $cost, 'serial_no' => $serialNo,
        ]);
    }

    public function test_a_fifo_issue_records_one_consumption_row_per_allocated_layer(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '2.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postReceiptAt($item, $uom, $location, '3.0000', '12.0000', Carbon::parse('2026-01-02'), 2);
        $layers = StockLayer::query()->orderBy('received_at')->get();

        $move = app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '3.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 3,
        ]);

        $consumptions = StockLayerConsumption::query()->where('stock_move_id', $move->id)->orderBy('stock_layer_id')->get();
        $this->assertCount(2, $consumptions);
        $this->assertSame($layers[0]->id, $consumptions[0]->stock_layer_id);
        $this->assertSame('2.0000', $consumptions[0]->qty_consumed);
        $this->assertSame('10.0000', $consumptions[0]->unit_cost);
        $this->assertSame($layers[1]->id, $consumptions[1]->stock_layer_id);
        $this->assertSame('1.0000', $consumptions[1]->qty_consumed);
        $this->assertSame('12.0000', $consumptions[1]->unit_cost);
    }

    public function test_a_fifo_transfer_records_a_consumption_row_only_for_the_source_layer(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $destination = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'other', 'location_name' => 'Other'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        $this->postReceiptAt($item, $uom, $location, '5.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $sourceLayer = StockLayer::query()->sole();

        $move = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => $destination->id,
            'qty' => '3.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-10'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 2,
        ]);

        $consumptions = StockLayerConsumption::query()->where('stock_move_id', $move->id)->get();
        $this->assertCount(1, $consumptions);
        $this->assertSame($sourceLayer->id, $consumptions->sole()->stock_layer_id);
        $this->assertSame('3.0000', $consumptions->sole()->qty_consumed);
        $newDestinationLayer = StockLayer::query()->where('location_id', $destination->id)->sole();
        $this->assertSame(0, StockLayerConsumption::query()->where('stock_layer_id', $newDestinationLayer->id)->count());
    }

    public function test_a_specific_issue_records_the_exact_serials_layer(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Specific, ItemTracking::Serial);
        $this->postReceiptAt($item, $uom, $location, '1.0000', '25.0000', Carbon::parse('2026-01-01'), 1, serialNo: 'SER-1');
        $layer = StockLayer::query()->sole();

        $move = app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '1.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
            'serial_no' => 'SER-1',
        ]);

        $consumption = StockLayerConsumption::query()->where('stock_move_id', $move->id)->sole();
        $this->assertSame($layer->id, $consumption->stock_layer_id);
        $this->assertSame('1.0000', $consumption->qty_consumed);
        $this->assertSame('25.0000', $consumption->unit_cost);
    }

    public function test_an_avco_issue_records_no_consumption_rows(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Avco);
        $this->postReceiptAt($item, $uom, $location, '5.0000', '10.0000', Carbon::parse('2026-01-01'), 1);

        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);

        $this->assertSame(0, StockLayerConsumption::query()->count());
    }

    public function test_an_avco_transfer_records_no_consumption_rows(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Avco);
        $destination = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'other', 'location_name' => 'Other'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        $this->postReceiptAt($item, $uom, $location, '5.0000', '10.0000', Carbon::parse('2026-01-01'), 1);

        app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => $destination->id,
            'qty' => '3.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-10'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 2,
        ]);

        $this->assertSame(0, StockLayerConsumption::query()->count());
    }

    public function test_consumption_rows_are_immutable(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '5.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $consumption = StockLayerConsumption::query()->sole();

        try {
            $consumption->update(['qty_consumed' => '999.0000']);
            $this->fail('Expected updating a stock layer consumption to be rejected.');
        } catch (DomainInvariantException) {
        }

        $this->expectException(DomainInvariantException::class);
        $consumption->delete();
    }
}
