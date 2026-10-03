<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CommitInventoryCutoverOpeningState;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverAvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class InventoryCutoverOpeningStatePostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve', 'inventory.cutover.commit'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_commits_avco_fifo_and_specific_opening_state_and_is_idempotent(): void
    {
        $this->createReferenceData();
        $cutover = $this->approvedCutover();

        $committer = $this->permittedUser('inventory.cutover.commit');
        $result = app(CommitInventoryCutoverOpeningState::class)($cutover, $committer);
        $this->assertSame('APPROVED', $result->status->value);
        $this->assertTrue($result->batches->every(fn ($batch): bool => $batch->status->value === 'COMMITTED'));

        $avcoItem = Item::query()->where('code', 'sku-avco')->firstOrFail();
        $this->assertSame(ValuationMethod::Avco, $avcoItem->valuation_method);
        $pool = AvcoPool::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('10.0000', $pool->qty_on_hand);
        $this->assertSame('125.0000', $pool->inventory_value);
        $this->assertSame('12.5000', $pool->avg_unit_cost);
        $provenance = InventoryCutoverAvcoPool::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('10.0000', $provenance->opening_quantity);
        $this->assertSame('12.5000', $provenance->derived_average);
        $avcoLayer = StockLayer::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('INVENTORY_CUTOVER_OPENING', $avcoLayer->source_type);

        $fifoItem = Item::query()->where('code', 'sku-fifo')->firstOrFail();
        $this->assertSame(ValuationMethod::Fifo, $fifoItem->valuation_method);
        $this->assertNull($fifoItem->avco_pool_scope);
        $this->assertSame(0, AvcoPool::query()->where('item_id', $fifoItem->id)->count());
        $fifoLayers = StockLayer::query()->where('item_id', $fifoItem->id)->orderBy('unit_cost')->get();
        $this->assertCount(2, $fifoLayers);
        $synthetic = $fifoLayers->firstWhere('unit_cost', '18.0000');
        $this->assertSame('INVENTORY_CUTOVER_SYNTHETIC', $synthetic->source_type);
        $this->assertSame('6.0000', $synthetic->qty_received);
        $this->assertTrue($synthetic->received_at->equalTo($cutover->refresh()->cutover_at));
        $real = $fifoLayers->firstWhere('unit_cost', '20.0000');
        $this->assertSame('INVENTORY_CUTOVER_OPENING', $real->source_type);
        $this->assertSame('4.0000', $real->qty_received);
        $this->assertSame('2026-07-15T00:00:00+00:00', $real->received_at->toIso8601String());

        $specificItem = Item::query()->where('code', 'sku-spec')->firstOrFail();
        $specificLayers = StockLayer::query()->where('item_id', $specificItem->id)->orderBy('serial_no')->get();
        $this->assertCount(2, $specificLayers);
        $this->assertSame(['SN-001', 'SN-002'], $specificLayers->pluck('serial_no')->all());
        $this->assertTrue($specificLayers->every(fn (StockLayer $layer): bool => $layer->qty_received === '1.0000'));

        $movesBefore = StockMove::query()->count();
        $again = app(CommitInventoryCutoverOpeningState::class)($cutover->refresh(), $committer);
        $this->assertSame($movesBefore, StockMove::query()->count());
        $this->assertTrue($again->batches->every(fn ($batch): bool => $batch->status->value === 'COMMITTED'));
    }

    public function test_actor_without_permission_cannot_commit_opening_state(): void
    {
        $this->createReferenceData();
        $cutover = $this->approvedCutover();

        $this->expectException(DomainInvariantException::class);
        app(CommitInventoryCutoverOpeningState::class)($cutover, User::factory()->create());
    }

    public function test_commit_requires_an_approved_cutover(): void
    {
        $this->createReferenceData();
        [$profile, $batch] = $this->validatedImportBatch($this->rows());
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        $cutover = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));

        $this->expectException(DomainInvariantException::class);
        app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));
    }

    public function test_a_failing_item_rolls_back_every_other_items_posting(): void
    {
        $this->createReferenceData();
        $cutover = $this->approvedCutover();
        // The AVCO item (posted first) keeps a resolvable account of its own; every
        // other item loses its category fallback, so the FIFO item fails mid-commit.
        $account = ChartOfAccount::query()->firstOrFail();
        Item::query()->where('code', 'sku-avco')->update(['inventory_account_id' => $account->id]);
        Category::query()->where('code', 'stock')->update(['inventory_account_id' => null]);

        try {
            app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));
            $this->fail('Expected the unresolvable inventory account to fail the whole commit.');
        } catch (DomainInvariantException) {
        }

        $this->assertSame('APPROVED', $cutover->refresh()->status->value);
        $this->assertTrue($cutover->batches->every(fn ($batch): bool => $batch->status->value === 'VALIDATED'));
        $this->assertSame(0, StockMove::query()->count());
        $this->assertSame(0, AvcoPool::query()->count());
        $this->assertSame(0, InventoryCutoverAvcoPool::query()->count());
        $this->assertSame(ValuationMethod::Avco, Item::query()->where('code', 'sku-fifo')->firstOrFail()->valuation_method, 'the FIFO item retargeting rolled back with everything else');
    }

    public function test_negative_opening_quantity_is_refused(): void
    {
        Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $uom = Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        $category = Category::query()->create([
            'code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        Item::query()->create([
            'code' => 'SKU-NEG', 'name' => 'Negative opening item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        app(CreateWarehouse::class)(['code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true, 'location_code' => 'STOCK', 'location_name' => 'Stock'], PurchasingActor::make(['inventory.warehouse.manage']));

        $rows = [[
            'source_id' => 'CUT-NEG-1', 'sku' => 'SKU-NEG', 'warehouse' => 'MAIN', 'location' => 'STOCK',
            'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-NEG', 'qty' => '-5.0000',
            'uom' => 'EA', 'cost' => '10.0000', 'value' => '-50.0000', 'base_value' => '-50.0000',
            'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
        ]];
        [, $batch] = $this->validatedImportBatch($rows);
        $input = $this->cutoverInput();
        $input['target_valuation_policy'] = ['SKU-NEG' => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM']];
        $input['control_quantity'] = '-5.0000';
        $input['control_value'] = '-50.0000';
        $input['base_control_value'] = '-50.0000';
        $cutover = app(CreateInventoryCutover::class)($input, $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        $cutover = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
        $cutover = app(ApproveInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.approve'));

        $this->expectException(DomainInvariantException::class);
        app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));
    }

    private function approvedCutover(): InventoryCutover
    {
        [, $batch] = $this->validatedImportBatch($this->rows());
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        $cutover = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));

        return app(ApproveInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.approve'));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'source_id' => 'CUT-AVCO-1', 'sku' => 'SKU-AVCO', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-AVCO', 'qty' => '10.0000',
                'uom' => 'EA', 'cost' => '12.5000', 'value' => '125.0000', 'base_value' => '125.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null,
                'effective' => '2026-08-01T00:00:00+05:00',
            ],
            [
                'source_id' => 'CUT-FIFO-REAL-1', 'sku' => 'SKU-FIFO', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'FIFO', 'scope' => null, 'pool' => null, 'qty' => '4.0000',
                'uom' => 'EA', 'cost' => '20.0000', 'value' => '80.0000', 'base_value' => '80.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null,
                'effective' => '2026-07-15T00:00:00+00:00',
            ],
            [
                'source_id' => 'CUT-FIFO-SYN-1', 'sku' => 'SKU-FIFO', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'FIFO', 'scope' => null, 'pool' => null, 'qty' => '6.0000',
                'uom' => 'EA', 'cost' => '18.0000', 'value' => '108.0000', 'base_value' => '108.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
            ],
            [
                'source_id' => 'CUT-SPEC-1', 'sku' => 'SKU-SPEC', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'SPECIFIC', 'scope' => null, 'pool' => null, 'qty' => '1.0000',
                'uom' => 'EA', 'cost' => '500.0000', 'value' => '500.0000', 'base_value' => '500.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => 'SN-001', 'effective' => null,
            ],
            [
                'source_id' => 'CUT-SPEC-2', 'sku' => 'SKU-SPEC', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'SPECIFIC', 'scope' => null, 'pool' => null, 'qty' => '1.0000',
                'uom' => 'EA', 'cost' => '550.0000', 'value' => '550.0000', 'base_value' => '550.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => 'SN-002', 'effective' => null,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{ImportProfile, ImportBatch}
     */
    private function validatedImportBatch(array $rows): array
    {
        $profile = app(CreateImportProfile::class)('Cutover opening', ImportPurpose::InventoryCutover, 'OPENING_STATE', [
            'source_id' => 'source_record_id', 'sku' => 'item_code', 'warehouse' => 'warehouse_code',
            'location' => 'location_code', 'method' => 'source_valuation_method', 'scope' => 'source_avco_scope',
            'pool' => 'source_pool_identity', 'qty' => 'quantity', 'uom' => 'uom_code', 'cost' => 'unit_cost',
            'value' => 'amount', 'base_value' => 'base_amount', 'currency' => 'currency_code', 'fx' => 'fx_rate',
            'batch' => 'batch_no', 'serial' => 'serial_no', 'effective' => 'source_effective_at',
        ]);
        $batch = app(StageImportBatch::class)($profile, ImportPurpose::InventoryCutover, 'cutover-batch-'.uniqid(), 'opening.csv', $rows, User::factory()->create());

        return [$profile, app(ValidateImportBatch::class)($batch)];
    }

    /** @return array<string, mixed> */
    private function cutoverInput(): array
    {
        return [
            'mode' => 'OPENING_STATE', 'cutover_at' => '2026-09-01T00:00:00+05:00',
            'source_system' => 'Legacy ERP',
            'target_valuation_policy' => [
                'SKU-AVCO' => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM'],
                'SKU-FIFO' => ['method' => 'FIFO'],
                'SKU-SPEC' => ['method' => 'SPECIFIC'],
            ],
            'control_quantity' => '22.0000', 'control_value' => '1363.0000', 'base_control_value' => '1363.0000',
        ];
    }

    private function createReferenceData(): void
    {
        Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $uom = Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        $category = Category::query()->create([
            'code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);

        Item::query()->create([
            'code' => 'SKU-AVCO', 'name' => 'AVCO cutover item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        Item::query()->create([
            'code' => 'SKU-FIFO', 'name' => 'FIFO cutover item (starts as AVCO)', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        Item::query()->create([
            'code' => 'SKU-SPEC', 'name' => 'SPECIFIC cutover item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Specific, 'tracking' => ItemTracking::Serial,
            'category_id' => $category->id,
        ]);

        app(CreateWarehouse::class)([
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'STOCK', 'location_name' => 'Stock',
        ], PurchasingActor::make(['inventory.warehouse.manage']));
    }

    /** @param string|array<int, string> $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
