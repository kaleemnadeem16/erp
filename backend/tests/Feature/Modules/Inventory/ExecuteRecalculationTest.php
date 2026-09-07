<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationExecution;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExecuteRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.recalculation.request', 'inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk', 'inventory.recalculation.execute'] as $permission) {
            Permission::findOrCreate($permission);
        }
        Company::query()->findOrFail(1)->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2020-01-01',
            'recalculation_approval_ttl_hours' => 24,
        ]);
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeAvcoItem(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();

        return [$item, $uom, $location];
    }

    /**
     * Initial receipt 5@10 on Jan 1, an issue of 2 on Jan 10, then a proposed
     * backdated receipt of 10@20 on Jan 5 — the same conflicting shape used
     * throughout DecideRecalculationApprovalTest.
     *
     * @return array{0: Item, 1: Uom, 2: Location, 3: RecalculationRequest, 4: User}
     */
    private function makeConflictingRequest(): array
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '10.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 3,
        ], $requester);

        return [$item, $uom, $location, $request, $requester];
    }

    private function approveFully(RecalculationImpactVersion $version): RecalculationImpactVersion
    {
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'checked', $approver);

        return $version->fresh()->load('approvals');
    }

    private function executor(): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.execute');

        return $actor;
    }

    public function test_execution_requires_permission(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $this->approveFully($request->impactVersions->sole());
        $actor = User::factory()->create();

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($version, $actor);
    }

    public function test_a_version_without_full_approval_cannot_execute(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $request->impactVersions->sole();

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($version, $this->executor());
    }

    public function test_a_rejected_version_cannot_execute(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $request->impactVersions->sole();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Rejected, 'not justified', $approver);

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($version->fresh(), $this->executor());
    }

    public function test_a_stale_version_cannot_execute(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $this->approveFully($request->impactVersions->sole());
        $version->update(['is_stale' => true]);

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($version->fresh(), $this->executor());
    }

    public function test_execution_requires_every_approval_to_be_currently_unexpired(): void
    {
        Company::query()->findOrFail(1)->update(['recalculation_high_risk_threshold_base_amount' => '1.0000']);
        [, , , $request] = $this->makeConflictingRequest();
        $version = $request->impactVersions->sole();
        $this->assertSame('HIGH_RISK', $version->risk_classification->value);
        $first = User::factory()->create();
        // Seeded already-expired, bypassing the Action, since approvals are
        // DB-immutable — Carbon::setTestNow() can't backdate an existing row,
        // and the guard trigger's expiry check compares against the
        // database's own now(), which a PHP-level mock never moves.
        DB::table('recalculation_approvals')->insert([
            'company_id' => 1, 'impact_version_id' => $version->id, 'decision' => 'APPROVED', 'notes' => 'first',
            'decided_by' => $first->id, 'decided_at' => now()->subHours(30), 'expires_at' => now()->subHours(6),
            'created_at' => now()->subHours(30), 'updated_at' => now()->subHours(30),
        ]);
        $second = User::factory()->create();
        $second->givePermissionTo(['inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk']);
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'second', $second);
        $version = $version->fresh()->load('approvals');
        $this->assertTrue($version->isFullyApproved());
        $this->assertFalse($version->hasUnexpiredFullApproval());

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($version, $this->executor());
    }

    public function test_execution_is_only_implemented_for_backdated_receipts(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'movement_kind' => 'ISSUE', 'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '1.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_PROPOSED_ISSUE', 'source_id' => 100,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('backdated receipts');
        app(ExecuteRecalculation::class)($version, $this->executor());
    }

    public function test_execution_posts_the_backdated_receipt_and_converges_the_pool_to_the_approved_counterfactual(): void
    {
        [$item, , $location, $request] = $this->makeConflictingRequest();
        $version = $this->approveFully($request->impactVersions->sole());

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $this->assertInstanceOf(RecalculationExecution::class, $execution);
        $this->assertSame($version->id, $execution->impact_version_id);
        $move = StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 3)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $this->assertTrue($move->moved_at->isSameDay(Carbon::parse('2026-01-05')));
        $this->assertSame('10.0000', $move->qty);

        $pool = AvcoPool::query()->where('item_id', $item->id)->sole();
        $this->assertSame($version->counterfactual_qty_on_hand, $pool->qty_on_hand);
        $this->assertSame($version->counterfactual_inventory_value, $pool->inventory_value);
        $this->assertSame($version->counterfactual_avg_unit_cost, $pool->avg_unit_cost);

        $entries = $move->valuationEntries()->orderBy('id')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame('DESTINATION_IN', $entries[0]->pool_effect);
        $this->assertSame('VALUATION_ADJUSTMENT', $entries[1]->pool_effect);
        $entriesTotal = BigDecimal::of($entries[0]->base_amount)->plus($entries[1]->base_amount);
        $this->assertTrue($entriesTotal->isEqualTo($version->valuation_adjustment));

        // Also serves as the location for the next test's idempotency check.
        $this->assertSame(1, RecalculationExecution::query()->where('recalculation_request_id', $request->id)->count());
    }

    public function test_execution_posts_a_specific_items_new_serial_with_no_pool_and_no_adjustment_entry(): void
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'SPECIFIC']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Specific, 'tracking' => 'SERIAL',
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();
        // Establishes the item-wide "later history exists" conflict — the
        // proposed serial (SER-NEW) never overlaps this one's own layer group.
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '1.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '25.0000', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => '25.0000',
            'serial_no' => 'SER-OTHER',
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '1.0000', 'unit_cost' => '30.0000', 'currency_id' => $currency->id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '30.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 2, 'serial_no' => 'SER-NEW',
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());
        $this->assertSame('30.0000', $version->valuation_adjustment);

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $move = StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 2)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $this->assertSame('SER-NEW', $move->serial_no);
        $entries = $move->valuationEntries()->get();
        $this->assertSame(1, $entries->count());
        $this->assertNull($entries->sole()->pool_effect);
        $this->assertSame('30.0000', $entries->sole()->base_amount);
        $this->assertSame(0, AvcoPool::query()->where('item_id', $item->id)->count());
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeFifoItem(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'FIFO']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Fifo,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();

        return [$item, $uom, $location];
    }

    /**
     * The pre-existing older layer (5@10, Jan 1) already covers the later
     * issue (2 units, Jan 10) on its own, so the backdated layer (10@20,
     * Jan 5 — chronologically between them) is never actually drawn from:
     * same fixture as RequestStockRecalculationTest::
     * test_fifo_items_compute_a_real_layer_based_impact.
     */
    public function test_execution_posts_a_fifo_receipt_untouched_by_later_history_with_no_adjustment_entry(): void
    {
        [$item, $uom, $location] = $this->makeFifoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '10.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 3,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());
        $this->assertSame('200.0000', $version->valuation_adjustment);

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $move = StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 3)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $entries = $move->valuationEntries()->get();
        $this->assertSame(1, $entries->count());
        $this->assertNull($entries->sole()->pool_effect);
        $this->assertSame('200.0000', $entries->sole()->base_amount);
        // Real history is untouched: the pre-existing layer's consumption
        // record from the Jan 10 issue is exactly what it always was.
        $this->assertSame(0, AvcoPool::query()->where('item_id', $item->id)->count());
    }

    /**
     * Older layer A (5@10, Jan 1) is exhausted by a later 6-unit issue that
     * also needs 1 unit from B (5@12, Jan 10) — real history. A backdated
     * layer C (3@20, Jan 5) sits between A and B in FIFO order: in the
     * counterfactual, A+C's combined 8 units cover the issue without ever
     * touching B, so B's actual consumption record no longer matches what
     * the counterfactual says should have happened. Execution appends an
     * exact reversal of B's allocation and a replacement allocation to C.
     */
    public function test_execution_reallocates_a_fifo_receipt_across_later_direct_issues_append_only(): void
    {
        [$item, $uom, $location] = $this->makeFifoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 2,
            'unit_cost' => '12.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '12.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '6.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-15'), 'source_type' => 'TEST_ISSUE', 'source_id' => 3,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '3.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 4,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());
        // Not 60.0000 (3 * 20, the receipt's own value alone) — the approved
        // preview already reflects that B's real 1-unit consumption would
        // not have happened counterfactually.
        $this->assertNotSame('60.0000', $version->valuation_adjustment);

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $issue = StockMove::query()->where('source_type', 'TEST_ISSUE')->where('source_id', 3)->sole();
        $layerB = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 2)->sole();
        $layerC = StockLayer::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 4)->sole();
        $this->assertSame('5.0000', $layerB->qty_remaining);
        $this->assertSame('2.0000', $layerC->qty_remaining);

        $rows = StockLayerConsumption::query()->where('stock_move_id', $issue->id)->orderBy('id')->get();
        $this->assertSame(4, $rows->count());
        $allocationB = $rows->first(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerB->id);
        $reversalB = $rows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION_REVERSAL');
        $replacementC = $rows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerC->id);
        $this->assertSame($allocationB->id, $reversalB->reverses_consumption_id);
        $this->assertSame($request->id, $reversalB->recalculation_request_id);
        $this->assertSame($request->id, $replacementC->recalculation_request_id);
        $this->assertSame('1.0000', $reversalB->qty_consumed);
        $this->assertSame('1.0000', $replacementC->qty_consumed);

        $entries = StockMove::query()->findOrFail($execution->stock_move_id)->valuationEntries()->orderBy('id')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame('60.0000', $entries[0]->base_amount);
        $this->assertSame('-8.0000', $entries[1]->base_amount);
        $this->assertTrue(BigDecimal::of($entries[0]->base_amount)->plus($entries[1]->base_amount)->isEqualTo($version->valuation_adjustment));
    }

    /**
     * Same fixture as before ADR 0040's transfer slice: layer A (5@10, Jan1)
     * and B (5@12, Jan10) at the source; a transfer of 6 units on Jan15 draws
     * A(5)+B(1), delivering destination layers D_A (5@10, age Jan1) and D_B
     * (1@12, age Jan10). A backdated layer C (3@20, Jan5) sits between A and
     * B: counterfactually the transfer draws A(5)+C(1) instead, so D_B's
     * one-unit component is superseded by a freshly minted destination layer
     * carrying C's cost/age, with nothing left downstream at the destination
     * to reallocate in this scenario (see the next test for that case).
     */
    public function test_execution_reallocates_a_fifo_transfer_and_corrects_destination_provenance(): void
    {
        [$item, $uom, $source] = $this->makeFifoItem();
        $destination = app(CreateWarehouse::class)([
            'code' => 'secondary', 'name' => 'Secondary', 'location_code' => 'secondary', 'location_name' => 'Secondary',
        ])->locations()->sole();
        foreach ([
            ['qty' => '5.0000', 'at' => '2026-01-01', 'cost' => '10.0000', 'source' => 1],
            ['qty' => '5.0000', 'at' => '2026-01-10', 'cost' => '12.0000', 'source' => 2],
        ] as $receipt) {
            app(PostInventoryMovement::class)([
                'item_id' => $item->id, 'location_id' => $source->id, 'qty' => $receipt['qty'], 'uom_id' => $uom->id,
                'moved_at' => Carbon::parse($receipt['at']), 'source_type' => 'TEST_RECEIPT', 'source_id' => $receipt['source'],
                'unit_cost' => $receipt['cost'], 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
                'fx_rate' => '1.000000000', 'base_unit_cost' => $receipt['cost'],
            ]);
        }
        $transfer = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => '6.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-15'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 3,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $source->id,
            'qty' => '3.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 4,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $this->assertTrue(StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 4)->exists());
        $layerB = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 2)->sole();
        $layerC = StockLayer::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 4)->sole();
        $this->assertSame('5.0000', $layerB->qty_remaining);
        $this->assertSame('2.0000', $layerC->qty_remaining);

        $destinationLayers = StockLayer::query()->where('source_type', 'TEST_TRANSFER')->where('source_id', 3)->orderBy('source_line_no')->get();
        $this->assertSame(3, $destinationLayers->count());
        $oldComponent = $destinationLayers[1];
        $newComponent = $destinationLayers[2];
        $this->assertSame('0.0000', $oldComponent->qty_remaining);
        $this->assertSame('1.0000', $newComponent->qty_received);
        $this->assertSame('1.0000', $newComponent->qty_remaining);
        $this->assertSame('20.0000', $newComponent->base_unit_cost);
        $this->assertTrue($newComponent->received_at->isSameDay(Carbon::parse('2026-01-05')));

        $reversal = StockLayerConsumption::query()->where('stock_move_id', $transfer->id)
            ->where('purpose', 'REVERSAL')->where('stock_layer_id', $oldComponent->id)->sole();
        $this->assertSame('1.0000', $reversal->qty_consumed);
        $this->assertSame($request->id, $reversal->recalculation_request_id);

        $entries = StockMove::query()->findOrFail($execution->stock_move_id)->valuationEntries()->orderBy('id')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame('60.0000', $entries[0]->base_amount);
        $this->assertSame('-8.0000', $entries[1]->base_amount);
        $this->assertTrue(BigDecimal::of($entries[0]->base_amount)->plus($entries[1]->base_amount)->isEqualTo($version->valuation_adjustment));
    }

    /**
     * The same transfer as above, but the destination doesn't sit idle: a
     * real issue of 6 units on Jan20 exhausts both D_A and D_B before the
     * backdated receipt is ever proposed. Correcting the transfer must also
     * release the issue's stale draw on D_B and re-point it to the freshly
     * minted destination layer — the same append-only pattern as a direct
     * issue at the source, just applied one hop further out.
     */
    public function test_execution_cascades_a_fifo_transfer_correction_into_a_destination_issue(): void
    {
        [$item, $uom, $source] = $this->makeFifoItem();
        $destination = app(CreateWarehouse::class)([
            'code' => 'secondary', 'name' => 'Secondary', 'location_code' => 'secondary', 'location_name' => 'Secondary',
        ])->locations()->sole();
        foreach ([
            ['qty' => '5.0000', 'at' => '2026-01-01', 'cost' => '10.0000', 'source' => 1],
            ['qty' => '5.0000', 'at' => '2026-01-10', 'cost' => '12.0000', 'source' => 2],
        ] as $receipt) {
            app(PostInventoryMovement::class)([
                'item_id' => $item->id, 'location_id' => $source->id, 'qty' => $receipt['qty'], 'uom_id' => $uom->id,
                'moved_at' => Carbon::parse($receipt['at']), 'source_type' => 'TEST_RECEIPT', 'source_id' => $receipt['source'],
                'unit_cost' => $receipt['cost'], 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
                'fx_rate' => '1.000000000', 'base_unit_cost' => $receipt['cost'],
            ]);
        }
        $transfer = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => '6.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-15'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 3,
        ]);
        $destinationIssue = app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $destination->id, 'qty' => '6.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-20'), 'source_type' => 'TEST_DESTINATION_ISSUE', 'source_id' => 5,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $source->id,
            'qty' => '3.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 4,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());

        app(ExecuteRecalculation::class)($version, $this->executor());

        $destinationLayers = StockLayer::query()->where('source_type', 'TEST_TRANSFER')->where('source_id', 3)->orderBy('source_line_no')->get();
        $this->assertSame(3, $destinationLayers->count());
        [$layerDA, $oldComponent, $newComponent] = $destinationLayers->all();
        $this->assertSame('0.0000', $oldComponent->qty_remaining);
        $this->assertSame('0.0000', $newComponent->qty_remaining);

        $issueRows = StockLayerConsumption::query()->where('stock_move_id', $destinationIssue->id)->orderBy('id')->get();
        $reversal = $issueRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION_REVERSAL');
        $replacement = $issueRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $newComponent->id);
        $originalOnA = $issueRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerDA->id);
        $this->assertSame('5.0000', $originalOnA->qty_consumed);
        $this->assertSame('1.0000', $reversal->qty_consumed);
        $this->assertSame('1.0000', $replacement->qty_consumed);
        $this->assertSame($request->id, $reversal->recalculation_request_id);
        $this->assertSame($request->id, $replacement->recalculation_request_id);
    }

    /**
     * The destination's own stock is transferred onward a second time before
     * the backdated receipt is proposed: source -> destination (transfer 1,
     * draws A(5)+B(1)) -> tertiary (transfer 2, draws all 6 units again,
     * i.e. the transfer-1 destination layers D_A(5)+D_B(1) in full). The
     * backdated layer C bumps B out of transfer 1 exactly as in the one-hop
     * test, and that correction must cascade a second time: transfer 2's own
     * draw on the now-superseded D_B is released and re-pointed at the newly
     * minted destination layer, which itself gets superseded and re-minted
     * one more time at tertiary. Recursion depth is no longer capped.
     */
    public function test_execution_cascades_a_fifo_correction_through_two_transfer_hops(): void
    {
        [$item, $uom, $source] = $this->makeFifoItem();
        $destination = app(CreateWarehouse::class)([
            'code' => 'secondary', 'name' => 'Secondary', 'location_code' => 'secondary', 'location_name' => 'Secondary',
        ])->locations()->sole();
        $tertiary = app(CreateWarehouse::class)([
            'code' => 'tertiary', 'name' => 'Tertiary', 'location_code' => 'tertiary', 'location_name' => 'Tertiary',
        ])->locations()->sole();
        foreach ([
            ['qty' => '5.0000', 'at' => '2026-01-01', 'cost' => '10.0000', 'source' => 1],
            ['qty' => '5.0000', 'at' => '2026-01-10', 'cost' => '12.0000', 'source' => 2],
        ] as $receipt) {
            app(PostInventoryMovement::class)([
                'item_id' => $item->id, 'location_id' => $source->id, 'qty' => $receipt['qty'], 'uom_id' => $uom->id,
                'moved_at' => Carbon::parse($receipt['at']), 'source_type' => 'TEST_RECEIPT', 'source_id' => $receipt['source'],
                'unit_cost' => $receipt['cost'], 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
                'fx_rate' => '1.000000000', 'base_unit_cost' => $receipt['cost'],
            ]);
        }
        $transfer1 = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => '6.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-15'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 3,
        ]);
        $transfer2 = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $destination->id, 'to_location_id' => $tertiary->id,
            'qty' => '6.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-20'),
            'source_type' => 'TEST_SECOND_TRANSFER', 'source_id' => 5,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $source->id,
            'qty' => '3.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 4,
        ], $requester);
        $version = $this->approveFully($request->impactVersions->sole());

        app(ExecuteRecalculation::class)($version, $this->executor());

        $layerB = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 2)->sole();
        $layerC = StockLayer::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 4)->sole();
        $this->assertSame('5.0000', $layerB->qty_remaining);
        $this->assertSame('2.0000', $layerC->qty_remaining);

        $destinationLayers = StockLayer::query()->where('source_type', 'TEST_TRANSFER')->where('source_id', 3)->orderBy('source_line_no')->get();
        $this->assertSame(3, $destinationLayers->count());
        [$layerDA, $layerDB, $layerDC] = $destinationLayers->all();
        $this->assertSame('0.0000', $layerDA->qty_remaining);
        $this->assertSame('0.0000', $layerDB->qty_remaining);
        $this->assertSame('20.0000', $layerDC->base_unit_cost);
        // D_C is itself fully carried onward by transfer 2's own correction.
        $this->assertSame('0.0000', $layerDC->qty_remaining);

        $tertiaryLayers = StockLayer::query()->where('source_type', 'TEST_SECOND_TRANSFER')->where('source_id', 5)->orderBy('source_line_no')->get();
        $this->assertSame(3, $tertiaryLayers->count());
        [$layerTA, $layerTB, $layerTC] = $tertiaryLayers->all();
        $this->assertSame('5.0000', $layerTA->qty_remaining);
        $this->assertSame('0.0000', $layerTB->qty_remaining);
        $this->assertSame('20.0000', $layerTC->base_unit_cost);
        $this->assertSame('1.0000', $layerTC->qty_remaining);
        $this->assertTrue($layerTC->received_at->isSameDay(Carbon::parse('2026-01-05')));

        $reversalOnTransfer1 = StockLayerConsumption::query()->where('stock_move_id', $transfer1->id)
            ->where('purpose', 'REVERSAL')->where('stock_layer_id', $layerDB->id)->sole();
        $reversalOnTransfer2 = StockLayerConsumption::query()->where('stock_move_id', $transfer2->id)
            ->where('purpose', 'REVERSAL')->where('stock_layer_id', $layerTB->id)->sole();
        $this->assertSame('1.0000', $reversalOnTransfer1->qty_consumed);
        $this->assertSame('1.0000', $reversalOnTransfer2->qty_consumed);
        $this->assertSame($request->id, $reversalOnTransfer1->recalculation_request_id);
        $this->assertSame($request->id, $reversalOnTransfer2->recalculation_request_id);

        $transfer2SourceRows = StockLayerConsumption::query()->where('stock_move_id', $transfer2->id)
            ->whereIn('purpose', ['ALLOCATION', 'ALLOCATION_REVERSAL'])->get();
        $releasedFromDB = $transfer2SourceRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION_REVERSAL');
        $consumedFromDC = $transfer2SourceRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerDC->id);
        $this->assertSame('1.0000', $releasedFromDB->qty_consumed);
        $this->assertSame('1.0000', $consumedFromDC->qty_consumed);

        // Company-wide physical quantity is conserved: the only net addition
        // anywhere is the backdated receipt's own 3 units.
        $total = StockLayer::query()->where('item_id', $item->id)->get()
            ->reduce(fn (BigDecimal $sum, StockLayer $layer): BigDecimal => $sum->plus($layer->qty_remaining), BigDecimal::zero());
        $this->assertTrue($total->isEqualTo('13.0000'));
    }

    public function test_fifo_execution_allows_an_unchanged_later_transfer(): void
    {
        [$item, $uom, $source] = $this->makeFifoItem();
        $destination = app(CreateWarehouse::class)([
            'code' => 'secondary', 'name' => 'Secondary', 'location_code' => 'secondary', 'location_name' => 'Secondary',
        ])->locations()->sole();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $source->id, 'qty' => '10.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        $transfer = app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => '2.0000', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-15'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 2,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $source->id,
            'qty' => '3.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 3,
        ], $requester);

        app(ExecuteRecalculation::class)($this->approveFully($request->impactVersions->sole()), $this->executor());

        $this->assertTrue(StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 3)->exists());
        $this->assertSame(1, StockLayerConsumption::query()->where('stock_move_id', $transfer->id)->count());
    }

    public function test_execution_is_idempotent(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $this->approveFully($request->impactVersions->sole());
        $actor = $this->executor();

        $first = app(ExecuteRecalculation::class)($version, $actor);
        $second = app(ExecuteRecalculation::class)($version->fresh(), $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockMove::query()->where('source_type', 'TEST_BACKDATED_RECEIPT')->where('source_id', 3)->count());
    }

    public function test_recalculation_executions_are_database_immutable(): void
    {
        [, , , $request] = $this->makeConflictingRequest();
        $version = $this->approveFully($request->impactVersions->sole());
        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $this->expectException(QueryException::class);
        DB::table('recalculation_executions')->where('id', $execution->id)->update(['executed_at' => now()]);
    }
}
