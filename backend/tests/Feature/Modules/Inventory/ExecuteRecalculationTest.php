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
