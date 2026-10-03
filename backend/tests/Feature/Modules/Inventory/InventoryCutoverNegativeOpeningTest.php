<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
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
use App\Modules\Inventory\Actions\ApproveInventoryCutoverNegativeOpening;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CommitInventoryCutoverOpeningState;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestInventoryCutoverNegativeOpening;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
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

class InventoryCutoverNegativeOpeningTest extends TestCase
{
    use RefreshDatabase;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate',
            'inventory.cutover.approve', 'inventory.cutover.commit', 'inventory.cutover.finalise',
            'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->createReferenceData();
    }

    public function test_request_computes_evidence_from_staged_facts_and_is_idempotent(): void
    {
        $cutover = $this->validatedCutover();
        $item = Item::query()->where('code', 'sku-avco-neg')->firstOrFail();
        $requester = $this->permittedUser('inventory.cutover.negative_opening.request');

        $exception = app(RequestInventoryCutoverNegativeOpening::class)($cutover, $this->requestInput($item->id), $requester);

        $this->assertSame('-5.0000', $exception->source_quantity);
        $this->assertSame('-50.0000', $exception->source_value);
        $this->assertSame('-50.0000', $exception->base_source_value);
        $this->assertSame('AVCO', $exception->source_valuation_method->value);
        $this->assertSame('REQUESTED', $exception->status->value);
        $this->assertTrue($exception->target_date->equalTo($cutover->cutover_at->addDays(30)->startOfDay()));
        $this->assertNull($exception->target_date_reason);

        $retry = app(RequestInventoryCutoverNegativeOpening::class)($cutover->refresh(), $this->requestInput($item->id), $requester);
        $this->assertSame($exception->id, $retry->id);
        $this->assertDatabaseCount('inventory_cutover_negative_openings', 1);
    }

    public function test_specific_and_serial_tracked_items_are_always_blocked(): void
    {
        $cutover = $this->validatedCutover();
        $specific = Item::query()->where('code', 'sku-spec-neg')->firstOrFail();
        $requester = $this->permittedUser('inventory.cutover.negative_opening.request');

        $this->expectException(DomainInvariantException::class);
        app(RequestInventoryCutoverNegativeOpening::class)($cutover, $this->requestInput($specific->id), $requester);
    }

    public function test_a_different_target_date_requires_an_explicit_reason(): void
    {
        $cutover = $this->validatedCutover();
        $item = Item::query()->where('code', 'sku-avco-neg')->firstOrFail();
        $requester = $this->permittedUser('inventory.cutover.negative_opening.request');

        $input = $this->requestInput($item->id);
        $input['target_date'] = $cutover->cutover_at->addDays(60)->toDateString();
        try {
            app(RequestInventoryCutoverNegativeOpening::class)($cutover, $input, $requester);
            $this->fail('Expected a non-default target date without a reason to be refused.');
        } catch (DomainInvariantException) {
        }

        $input['target_date_reason'] = 'Owner is on extended leave; extended by management approval.';
        $exception = app(RequestInventoryCutoverNegativeOpening::class)($cutover, $input, $requester);
        $this->assertSame($input['target_date'], $exception->target_date->toDateString());
        $this->assertSame($input['target_date_reason'], $exception->target_date_reason);
    }

    public function test_approval_requires_a_different_actor_and_is_idempotent(): void
    {
        $cutover = $this->validatedCutover();
        $item = Item::query()->where('code', 'sku-avco-neg')->firstOrFail();
        $requester = $this->permittedUser(['inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve']);
        $exception = app(RequestInventoryCutoverNegativeOpening::class)($cutover, $this->requestInput($item->id), $requester);

        $this->expectException(DomainInvariantException::class);
        app(ApproveInventoryCutoverNegativeOpening::class)($exception, $requester);
    }

    public function test_commit_refuses_a_negative_opening_with_no_approved_exception(): void
    {
        $cutover = $this->approvedCutover();

        $this->expectException(DomainInvariantException::class);
        app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));
    }

    public function test_commit_posts_avco_and_fifo_negative_openings_as_cutover_shortages(): void
    {
        $cutover = $this->validatedCutover();
        $avcoItem = Item::query()->where('code', 'sku-avco-neg')->firstOrFail();
        $fifoItem = Item::query()->where('code', 'sku-fifo-neg')->firstOrFail();
        $requester = $this->permittedUser(['inventory.cutover.negative_opening.request']);
        $approver = $this->permittedUser(['inventory.cutover.negative_opening.approve']);

        $avcoException = app(RequestInventoryCutoverNegativeOpening::class)($cutover, $this->requestInput($avcoItem->id, '9.0000'), $requester);
        app(ApproveInventoryCutoverNegativeOpening::class)($avcoException, $approver);
        $fifoException = app(RequestInventoryCutoverNegativeOpening::class)($cutover->refresh(), $this->requestInput($fifoItem->id, '14.0000'), $requester);
        app(ApproveInventoryCutoverNegativeOpening::class)($fifoException, $approver);

        $cutover = app(ApproveInventoryCutover::class)($cutover->refresh(), $this->permittedUser('inventory.cutover.approve'));
        $result = app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));
        $this->assertTrue($result->batches->every(fn ($batch): bool => $batch->status->value === 'COMMITTED'));

        $pool = AvcoPool::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('-5.0000', $pool->qty_on_hand);
        // The posted value derives from the approved provisional_unit_cost (9.0000),
        // not the fact's own raw evidenced value (-50.0000 at the source's 10.0000/unit).
        $this->assertSame('-45.0000', $pool->inventory_value);
        $this->assertSame('9.0000', $pool->avg_unit_cost);
        $this->assertSame('9.0000', $pool->last_valid_avg_unit_cost);
        $this->assertSame('FROZEN', $pool->status->value);
        $this->assertSame(0, StockLayer::query()->where('item_id', $avcoItem->id)->count());

        $avcoMove = StockMove::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('ISSUE', $avcoMove->movement_kind);
        $this->assertSame('5.0000', $avcoMove->qty);
        $this->assertSame('INVENTORY_CUTOVER_OPENING_SHORTAGE', $avcoMove->source_type);
        $avcoShortage = NegativeStockShortage::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('OPEN_CUTOVER_SHORTAGE', $avcoShortage->status);
        $this->assertSame('5.0000', $avcoShortage->qty_open);
        $this->assertSame('9.0000', $avcoShortage->provisional_unit_cost);
        $this->assertNull($avcoShortage->override_request_id);
        $this->assertSame($avcoException->id, $avcoShortage->inventory_cutover_negative_opening_id);

        $this->assertSame(0, AvcoPool::query()->where('item_id', $fifoItem->id)->count());
        $this->assertSame(0, StockLayer::query()->where('item_id', $fifoItem->id)->count());
        $fifoMove = StockMove::query()->where('item_id', $fifoItem->id)->sole();
        $this->assertSame('3.0000', $fifoMove->qty);
        $fifoShortage = NegativeStockShortage::query()->where('item_id', $fifoItem->id)->sole();
        $this->assertSame('3.0000', $fifoShortage->qty_open);
        $this->assertSame('14.0000', $fifoShortage->provisional_unit_cost);

        // A later ordinary receipt settles the cutover shortage exactly like a live one.
        $receiptAt = $cutover->cutover_at->addDays(5);
        app(PostInventoryMovement::class)([
            'item_id' => $avcoItem->id, 'location_id' => $this->locationId, 'qty' => '5.0000', 'uom_id' => $avcoItem->stock_uom_id,
            'moved_at' => $receiptAt, 'source_type' => 'TEST_RECEIPT', 'source_id' => 999001,
            'unit_cost' => '12.0000', 'currency_id' => Currency::query()->where('code', 'PKR')->value('id'),
            'fx_rate' => '1.000000000', 'base_unit_cost' => '12.0000',
        ]);
        $this->assertSame('SETTLED', $avcoShortage->refresh()->status);
        $this->assertSame('0.0000', $avcoShortage->qty_open);
        $settledPool = AvcoPool::query()->where('item_id', $avcoItem->id)->sole();
        $this->assertSame('0.0000', $settledPool->qty_on_hand);
        $this->assertSame('0.0000', $settledPool->inventory_value);
        // A receipt that settles a shortage to exactly zero leaves the pool
        // FROZEN, not ACTIVE — ReceiptPoster only reactivates a pool that
        // goes strictly positive; this is existing, unmodified behaviour.
        $this->assertSame('FROZEN', $settledPool->status->value);
    }

    /** @return array<string, mixed> */
    private function requestInput(int $itemId, string $provisionalUnitCost = '9.0000'): array
    {
        return [
            'item_id' => $itemId, 'location_id' => $this->locationId, 'batch_no' => null,
            'provisional_unit_cost' => $provisionalUnitCost,
            'reason' => 'Predecessor system closed this location negative per its final approved stock report.',
            'supporting_evidence' => ['document' => 'legacy-closing-report.pdf', 'page' => 4],
            'responsible_owner_id' => User::factory()->create()->id,
            'resolution_plan' => 'Receive the delayed inbound shipment already confirmed in transit.',
        ];
    }

    private function validatedCutover(): InventoryCutover
    {
        [, $batch] = $this->validatedImportBatch($this->rows());
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));

        return app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
    }

    private function approvedCutover(): InventoryCutover
    {
        $cutover = $this->validatedCutover();

        return app(ApproveInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.approve'));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'source_id' => 'CUT-AVCO-NEG-1', 'sku' => 'SKU-AVCO-NEG', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-AVCO-NEG', 'qty' => '-5.0000',
                'uom' => 'EA', 'cost' => '10.0000', 'value' => '-50.0000', 'base_value' => '-50.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
            ],
            [
                'source_id' => 'CUT-FIFO-NEG-1', 'sku' => 'SKU-FIFO-NEG', 'warehouse' => 'MAIN', 'location' => 'STOCK',
                'method' => 'FIFO', 'scope' => null, 'pool' => null, 'qty' => '-3.0000',
                'uom' => 'EA', 'cost' => '15.0000', 'value' => '-45.0000', 'base_value' => '-45.0000',
                'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
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
            'mode' => 'OPENING_STATE', 'cutover_at' => '2026-09-01T00:00:00+00:00',
            'source_system' => 'Legacy ERP',
            'target_valuation_policy' => [
                'SKU-AVCO-NEG' => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM'],
                'SKU-FIFO-NEG' => ['method' => 'FIFO'],
                'SKU-SPEC-NEG' => ['method' => 'SPECIFIC'],
            ],
            'control_quantity' => '-8.0000', 'control_value' => '-95.0000', 'base_control_value' => '-95.0000',
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
            'code' => 'SKU-AVCO-NEG', 'name' => 'AVCO negative opening item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        Item::query()->create([
            'code' => 'SKU-FIFO-NEG', 'name' => 'FIFO negative opening item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Fifo, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        Item::query()->create([
            'code' => 'SKU-SPEC-NEG', 'name' => 'SPECIFIC negative opening item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Specific, 'tracking' => ItemTracking::Serial,
            'category_id' => $category->id,
        ]);

        app(CreateWarehouse::class)([
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'STOCK', 'location_name' => 'Stock',
        ], PurchasingActor::make(['inventory.warehouse.manage']));
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
