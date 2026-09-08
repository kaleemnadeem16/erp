<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CancelPhysicalCount;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhysicalCountTest extends TestCase
{
    use RefreshDatabase;

    private int $locationId;

    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->createReferenceData();
    }

    public function test_posts_positive_and_negative_variances_across_valuation_methods(): void
    {
        $avcoItem = $this->receiveAndReturnItem('SKU-CNT-AVCO', ValuationMethod::Avco, ItemTracking::None, '20.0000', '10.0000');
        $fifoItem = $this->receiveAndReturnItem('SKU-CNT-FIFO', ValuationMethod::Fifo, ItemTracking::None, '12.0000', '8.0000');
        $specItem = $this->createItem('SKU-CNT-SPEC', ValuationMethod::Specific, ItemTracking::Serial);
        $this->receiveSerial($specItem, 'SN-100', '50.0000');

        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $avcoLine = app(AddPhysicalCountLine::class)($count, ['item_id' => $avcoItem->id, 'location_id' => $this->locationId], $creator);
        $fifoLine = app(AddPhysicalCountLine::class)($count->refresh(), ['item_id' => $fifoItem->id, 'location_id' => $this->locationId], $creator);
        $specExistingLine = app(AddPhysicalCountLine::class)($count->refresh(), ['item_id' => $specItem->id, 'location_id' => $this->locationId, 'serial_no' => 'SN-100'], $creator);
        $specNewLine = app(AddPhysicalCountLine::class)($count->refresh(), ['item_id' => $specItem->id, 'location_id' => $this->locationId, 'serial_no' => 'SN-200'], $creator);

        $this->assertSame('20.0000', $avcoLine->book_qty);
        $this->assertSame('12.0000', $fifoLine->book_qty);
        $this->assertSame('1.0000', $specExistingLine->book_qty);
        $this->assertSame('0.0000', $specNewLine->book_qty);

        app(RecordPhysicalCountLine::class)($avcoLine, '15.0000', $counter);
        app(RecordPhysicalCountLine::class)($fifoLine, '14.0000', $counter);
        app(RecordPhysicalCountLine::class)($specExistingLine, '0', $counter);
        app(RecordPhysicalCountLine::class)($specNewLine, '1', $counter);

        $result = app(PostPhysicalCount::class)($count->refresh(), [
            $fifoLine->id => '9.0000', $specNewLine->id => '55.0000',
        ], $poster);
        $this->assertSame('POSTED', $result->status->value);

        $avcoPool = AvcoPool::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('15.0000', $avcoPool->qty_on_hand);
        $this->assertSame('150.0000', $avcoPool->inventory_value);
        $this->assertSame('10.0000', $avcoPool->avg_unit_cost);
        $avcoMove = StockMove::query()->where('item_id', $avcoItem->id)->where('source_type', 'PHYSICAL_COUNT_LINE')->sole();
        $this->assertSame('ISSUE', $avcoMove->movement_kind);
        $this->assertSame('5.0000', $avcoMove->qty);
        $this->assertNull(NegativeStockShortage::query()->where('item_id', $avcoItem->id)->first());

        $fifoLayers = StockLayer::query()->where('item_id', $fifoItem->id)->orderBy('unit_cost')->get();
        $this->assertCount(2, $fifoLayers);
        $newFifoLayer = $fifoLayers->firstWhere('unit_cost', '9.0000');
        $this->assertSame('2.0000', $newFifoLayer->qty_received);
        $fifoMove = StockMove::query()->where('item_id', $fifoItem->id)->where('source_type', 'PHYSICAL_COUNT_LINE')->sole();
        $this->assertSame('RECEIPT', $fifoMove->movement_kind);

        $specLayers = StockLayer::query()->where('item_id', $specItem->id)->get();
        $this->assertSame('0.0000', $specLayers->firstWhere('serial_no', 'SN-100')->qty_remaining);
        $newSerialLayer = $specLayers->firstWhere('serial_no', 'SN-200');
        $this->assertSame('1.0000', $newSerialLayer->qty_received);
        $this->assertSame('55.0000', $newSerialLayer->unit_cost);

        $movesBefore = StockMove::query()->count();
        $again = app(PostPhysicalCount::class)($count->refresh(), [], $poster);
        $this->assertSame('POSTED', $again->status->value);
        $this->assertSame($movesBefore, StockMove::query()->count());
    }

    public function test_zero_variance_creates_no_move(): void
    {
        $item = $this->receiveAndReturnItem('SKU-CNT-ZERO', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->locationId], $creator);
        app(RecordPhysicalCountLine::class)($line, '10.0000', $counter);

        app(PostPhysicalCount::class)($count->refresh(), [], $poster);
        $line->refresh();
        $this->assertNull($line->posted_stock_move_id);
        $this->assertSame(0, StockMove::query()->where('item_id', $item->id)->where('source_type', 'PHYSICAL_COUNT_LINE')->count());
    }

    public function test_recount_replaces_the_working_count_and_keeps_history(): void
    {
        $item = $this->receiveAndReturnItem('SKU-CNT-RECOUNT', ValuationMethod::Avco, ItemTracking::None, '100.0000', '4.0000');
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->locationId], $creator);

        app(RecordPhysicalCountLine::class)($line, '94.0000', $counter);
        $recounted = app(RecordPhysicalCountLine::class)($line->refresh(), '97.0000', $counter);

        $this->assertSame('97.0000', $recounted->counted_qty);
        $this->assertSame(2, $recounted->entries()->count());
        $this->assertSame(['94.0000', '97.0000'], $recounted->entries()->orderBy('id')->pluck('counted_qty')->all());
    }

    public function test_positive_variance_requires_a_found_unit_cost(): void
    {
        $item = $this->receiveAndReturnItem('SKU-CNT-COST', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->locationId], $creator);
        app(RecordPhysicalCountLine::class)($line, '12.0000', $counter);

        $this->expectException(DomainInvariantException::class);
        app(PostPhysicalCount::class)($count->refresh(), [], $poster);
    }

    public function test_poster_cannot_be_the_creator(): void
    {
        $item = $this->receiveAndReturnItem('SKU-CNT-INDEP', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $creator = $this->permittedUser(['inventory.physical_count.create', 'inventory.physical_count.post']);
        $counter = $this->permittedUser('inventory.physical_count.count');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->locationId], $creator);
        app(RecordPhysicalCountLine::class)($line, '10.0000', $counter);

        $this->expectException(DomainInvariantException::class);
        app(PostPhysicalCount::class)($count->refresh(), [], $creator);
    }

    public function test_post_requires_every_line_to_be_counted(): void
    {
        $itemA = $this->receiveAndReturnItem('SKU-CNT-A', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $itemB = $this->receiveAndReturnItem('SKU-CNT-B', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $lineA = app(AddPhysicalCountLine::class)($count, ['item_id' => $itemA->id, 'location_id' => $this->locationId], $creator);
        app(AddPhysicalCountLine::class)($count->refresh(), ['item_id' => $itemB->id, 'location_id' => $this->locationId], $creator);
        app(RecordPhysicalCountLine::class)($lineA, '10.0000', $counter);

        $this->expectException(DomainInvariantException::class);
        app(PostPhysicalCount::class)($count->refresh(), [], $poster);
    }

    public function test_cancelling_a_draft_blocks_further_counting_and_posting(): void
    {
        $item = $this->receiveAndReturnItem('SKU-CNT-CANCEL', ValuationMethod::Avco, ItemTracking::None, '10.0000', '5.0000');
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');

        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $this->warehouseId], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->locationId], $creator);
        $cancelled = app(CancelPhysicalCount::class)($count->refresh(), $creator);
        $this->assertSame('CANCELLED', $cancelled->status->value);

        $this->expectException(DomainInvariantException::class);
        app(RecordPhysicalCountLine::class)($line, '5.0000', $counter);
    }

    private function receiveAndReturnItem(string $code, ValuationMethod $method, ItemTracking $tracking, string $qty, string $unitCost): Item
    {
        $item = $this->createItem($code, $method, $tracking);
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $this->locationId, 'qty' => $qty, 'uom_id' => $item->stock_uom_id,
            'moved_at' => now()->subDays(10), 'source_type' => 'TEST_RECEIPT', 'source_id' => random_int(1, 100000000),
            'unit_cost' => $unitCost, 'currency_id' => Currency::query()->where('code', 'PKR')->value('id'),
            'fx_rate' => '1.000000000', 'base_unit_cost' => $unitCost,
        ]);

        return $item;
    }

    private function receiveSerial(Item $item, string $serialNo, string $unitCost): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $this->locationId, 'qty' => '1.0000', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now()->subDays(10), 'source_type' => 'TEST_RECEIPT', 'source_id' => random_int(1, 100000000),
            'unit_cost' => $unitCost, 'currency_id' => Currency::query()->where('code', 'PKR')->value('id'),
            'fx_rate' => '1.000000000', 'base_unit_cost' => $unitCost, 'serial_no' => $serialNo,
        ]);
    }

    private function createItem(string $code, ValuationMethod $method, ItemTracking $tracking): Item
    {
        $uom = Uom::query()->where('code', 'EA')->firstOrFail();
        $category = Category::query()->where('code', 'stock')->firstOrFail();

        return Item::query()->create([
            'code' => $code, 'name' => $code, 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'category_id' => $category->id,
        ]);
    }

    private function createReferenceData(): void
    {
        Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        Category::query()->create([
            'code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $warehouse = app(CreateWarehouse::class)([
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'STOCK', 'location_name' => 'Stock',
        ]);
        $this->warehouseId = $warehouse->id;
        $this->locationId = (int) Location::query()->where('code', 'stock')->value('id');
        Company::query()->findOrFail(1)->update(['base_currency_id' => Currency::query()->where('code', 'PKR')->value('id')]);
    }

    /** @param string|array<int, string> $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
