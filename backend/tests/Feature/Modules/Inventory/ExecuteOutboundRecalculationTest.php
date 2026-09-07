<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

/**
 * Covers ExecuteRecalculation's clean-replay outbound branch only — an
 * approved backdated ISSUE/TRANSFER whose revalidated movement_impact has no
 * changed_movements. A request whose replay would reprice other history
 * still refuses; see test_execution_refuses_when_the_replay_changes_other_history.
 */
class ExecuteOutboundRecalculationTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    private function approveFully(RecalculationImpactVersion $version): RecalculationImpactVersion
    {
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'checked', $approver);

        return $version->fresh()->load('approvals');
    }

    private function executor(): User
    {
        Permission::findOrCreate('inventory.recalculation.execute');
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.execute');

        return $actor;
    }

    public static function methods(): array
    {
        return [[ValuationMethod::Fifo], [ValuationMethod::Avco]];
    }

    /**
     * A single-cost layer means the later issue draws the same cost either
     * way, so nothing about it changes — a full counterfactual drawdown to
     * zero, same fixture as OutboundRecalculationTest::
     * test_complete_counterfactual_drawdown_leaves_no_quantity_or_value_residual.
     */
    #[DataProvider('methods')]
    public function test_execution_posts_a_clean_backdated_issue_at_its_true_date(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '2', '2026-01-10', 2);
        $request = $this->request($a, '3', '2026-01-05');
        $version = $this->approveFully($request->impactVersions->sole());

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $move = StockMove::query()->where('source_type', 'TEST_PROPOSED')->where('source_id', 100)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $this->assertSame('ISSUE', $move->movement_kind);
        $this->assertTrue($move->moved_at->isSameDay(Carbon::parse('2026-01-05')));
        $this->assertSame('3.0000', $move->qty);
        $this->assertSame('10.0000', $move->unit_cost);

        $layer = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 1)->sole();
        // 5 received; the real Jan-10 issue (2) plus this backdated one (3)
        // together exhaust it completely.
        $this->assertSame('0.0000', $layer->qty_remaining);

        if ($method === ValuationMethod::Avco) {
            $pool = AvcoPool::query()->sole();
            $this->assertSame('0.0000', $pool->qty_on_hand);
            $this->assertSame('0.0000', $pool->inventory_value);
            $entry = $move->valuationEntries()->sole();
            $this->assertSame('SOURCE_OUT', $entry->pool_effect);
            $this->assertSame('-30.0000', $entry->base_amount);
        } else {
            $consumption = StockLayerConsumption::query()->where('stock_move_id', $move->id)->sole();
            $this->assertSame($layer->id, $consumption->stock_layer_id);
            $this->assertNull($consumption->recalculation_request_id);
            $entry = $move->valuationEntries()->sole();
            $this->assertSame('-30.0000', $entry->base_amount);
        }
    }

    public function test_execution_posts_a_clean_specific_issue(): void
    {
        $this->fixture(ValuationMethod::Specific);
        $a = $this->location('a');
        $this->receive($a, '1', '10', '2026-01-01', 1, 'S1');
        $this->receive($a, '1', '30', '2026-01-10', 2, 'S2');
        $request = $this->request($a, '1', '2026-01-05', serial: 'S1');
        $version = $this->approveFully($request->impactVersions->sole());

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $move = StockMove::query()->where('source_type', 'TEST_PROPOSED')->where('source_id', 100)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $this->assertSame('S1', $move->serial_no);
        $layerS1 = StockLayer::query()->where('serial_no', 'S1')->sole();
        $this->assertSame('0.0000', $layerS1->qty_remaining);
        $layerS2 = StockLayer::query()->where('serial_no', 'S2')->sole();
        $this->assertSame('1.0000', $layerS2->qty_remaining);
    }

    /**
     * A single-cost source layer again keeps the later issue's own cost
     * unchanged regardless of the earlier transfer, so this stays clean for
     * both methods under the default company-wide AVCO pool.
     */
    #[DataProvider('methods')]
    public function test_execution_posts_a_clean_backdated_transfer(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '1', '2026-01-10', 2);
        $request = $this->request($a, '2', '2026-01-05', $b);
        $version = $this->approveFully($request->impactVersions->sole());

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $move = StockMove::query()->where('source_type', 'TEST_PROPOSED')->where('source_id', 100)->sole();
        $this->assertSame($execution->stock_move_id, $move->id);
        $this->assertSame('TRANSFER', $move->movement_kind);
        $this->assertSame($a->id, $move->from_location_id);
        $this->assertSame($b->id, $move->to_location_id);

        $destinationLayer = StockLayer::query()->where('location_id', $b->id)->sole();
        $this->assertSame('2.0000', $destinationLayer->qty_remaining);
        $this->assertSame('10.0000', $destinationLayer->base_unit_cost);
        $sourceLayer = StockLayer::query()->where('location_id', $a->id)->sole();
        // 5 received - 2 (this transfer) - 1 (the real later issue) = 2.
        $this->assertSame('2.0000', $sourceLayer->qty_remaining);

        if ($method === ValuationMethod::Avco) {
            // Company-wide scope: source and destination share one pool, so
            // a same-pool transfer changes custody but not pool quantity —
            // it stays at 5 received minus the real Jan-10 issue of 1.
            $this->assertSame(1, AvcoPool::query()->count());
            $pool = AvcoPool::query()->sole();
            $this->assertSame('4.0000', $pool->qty_on_hand);
        }
    }

    public function test_execution_posts_a_clean_cross_pool_avco_transfer_to_a_new_warehouse(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '1', '2026-01-10', 2);
        $request = $this->request($a, '2', '2026-01-05', $b);
        $version = $this->approveFully($request->impactVersions->sole());

        app(ExecuteRecalculation::class)($version, $this->executor());

        $sourcePool = AvcoPool::query()->where('warehouse_id', $a->warehouse_id)->sole();
        $destinationPool = AvcoPool::query()->where('warehouse_id', $b->warehouse_id)->sole();
        $this->assertNotSame($sourcePool->id, $destinationPool->id);
        $this->assertSame('2.0000', $sourcePool->qty_on_hand);
        $this->assertSame('2.0000', $destinationPool->qty_on_hand);
        $this->assertSame('20.0000', $destinationPool->inventory_value);
    }

    public function test_execution_posts_a_clean_specific_transfer(): void
    {
        $this->fixture(ValuationMethod::Specific);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '1', '10', '2026-01-01', 1, 'S1');
        $this->receive($a, '1', '30', '2026-01-10', 2, 'S2');
        $request = $this->request($a, '1', '2026-01-05', $b, 'S1');
        $version = $this->approveFully($request->impactVersions->sole());

        app(ExecuteRecalculation::class)($version, $this->executor());

        $destinationLayer = StockLayer::query()->where('location_id', $b->id)->where('serial_no', 'S1')->sole();
        $this->assertSame('1.0000', $destinationLayer->qty_remaining);
        $this->assertSame('10.0000', $destinationLayer->base_unit_cost);
        $this->assertSame(0, StockLayer::query()->where('location_id', $a->id)->where('serial_no', 'S1')->where('qty_remaining', '>', 0)->count());
    }

    /**
     * Two different-cost receipts before the later issue mean an earlier
     * backdated draw reprices it — same fixture as OutboundRecalculationTest::
     * test_backdated_issue_derives_cost_and_reprices_later_consumption_without_posting.
     * The later issue's own real ALLOCATION row (2 units off the 5@10 layer)
     * becomes wrong once the backdated draw takes 4 of that same layer first:
     * it must give back 1 unit to the 5@10 layer and draw 1 more from the
     * 5@20 layer instead — an append-only reversal/replacement pair, same
     * shape as a receipt correcting a later issue.
     */
    public function test_execution_cascades_a_fifo_backdated_issue_into_a_later_real_issue(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($a, '5', '20', '2026-01-10', 2);
        $this->issue($a, '2', '2026-01-12', 3);
        $laterIssue = StockMove::query()->where('source_type', 'TEST_ISSUE')->where('source_id', 3)->sole();
        $request = $this->request($a, '4', '2026-01-05');
        $version = $this->approveFully($request->impactVersions->sole());
        $this->assertNotEmpty($version->movement_impact['changed_movements']);

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $layerA = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 1)->sole();
        $layerB = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 2)->sole();
        $this->assertSame('0.0000', $layerA->qty_remaining);
        $this->assertSame('4.0000', $layerB->qty_remaining);

        $laterRows = StockLayerConsumption::query()->where('stock_move_id', $laterIssue->id)->orderBy('id')->get();
        $original = $laterRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerA->id && $row->recalculation_request_id === null);
        $reversal = $laterRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION_REVERSAL');
        $replacement = $laterRows->sole(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION' && $row->stock_layer_id === $layerB->id);
        $this->assertSame($original->id, $reversal->reverses_consumption_id);
        $this->assertSame('1.0000', $reversal->qty_consumed);
        $this->assertSame('1.0000', $replacement->qty_consumed);
        $this->assertSame($request->id, $reversal->recalculation_request_id);
        $this->assertSame($request->id, $replacement->recalculation_request_id);

        // Own entry (4 @ 10 = -40) plus the residual that reconciles the
        // rest of the approved adjustment to the later issue's re-costing.
        $entries = StockMove::query()->findOrFail($execution->stock_move_id)->valuationEntries()->orderBy('id')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame('-40.0000', $entries[0]->base_amount);
        $this->assertTrue(BigDecimal::of($entries[0]->base_amount)->plus($entries[1]->base_amount)->isEqualTo($version->valuation_adjustment));
    }

    /**
     * Same fixture, AVCO: no per-layer ledger to correct, so the whole
     * correction is the pool converging to its counterfactual snapshot plus
     * a residual reconciling the new move's own value to the full approved
     * adjustment — the later issue's own historical entry is never rewritten.
     */
    public function test_execution_cascades_a_avco_backdated_issue_into_a_later_real_issue(): void
    {
        $this->fixture(ValuationMethod::Avco);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($a, '5', '20', '2026-01-10', 2);
        $this->issue($a, '2', '2026-01-12', 3);
        $request = $this->request($a, '4', '2026-01-05');
        $version = $this->approveFully($request->impactVersions->sole());
        $this->assertNotEmpty($version->movement_impact['changed_movements']);
        $this->assertSame('73.3334', $version->counterfactual_inventory_value);

        $execution = app(ExecuteRecalculation::class)($version, $this->executor());

        $pool = AvcoPool::query()->sole();
        $this->assertSame('4.0000', $pool->qty_on_hand);
        $this->assertSame('73.3334', $pool->inventory_value);

        $entries = StockMove::query()->findOrFail($execution->stock_move_id)->valuationEntries()->orderBy('id')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame('SOURCE_OUT', $entries[0]->pool_effect);
        $this->assertSame('-40.0000', $entries[0]->base_amount);
        $this->assertSame('VALUATION_ADJUSTMENT', $entries[1]->pool_effect);
        $this->assertTrue(BigDecimal::of($entries[0]->base_amount)->plus($entries[1]->base_amount)->isEqualTo($version->valuation_adjustment));
    }

    /**
     * The backdated issue also bumps a *later real transfer's* allocation —
     * it drew 5@10+1@20 for real; counterfactually it should draw 1@10+5@20.
     * Correcting it cascades into its own destination exactly like a
     * receipt's transfer cascade: the 5-unit component sourced from the
     * cheaper layer is superseded down to 1, and a freshly minted component
     * carries the extra 4 units at the dearer layer's cost/age.
     */
    public function test_execution_cascades_a_fifo_backdated_issue_into_a_later_real_transfer(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($a, '5', '20', '2026-01-10', 2);
        $this->transfer($a, $b, '6', '2026-01-15', 3);
        $transfer = StockMove::query()->where('source_type', 'TEST_TRANSFER')->where('source_id', 3)->sole();
        $request = $this->request($a, '4', '2026-01-05');
        $version = $this->approveFully($request->impactVersions->sole());

        app(ExecuteRecalculation::class)($version, $this->executor());

        $layerA = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 1)->sole();
        $layerB = StockLayer::query()->where('source_type', 'TEST_RECEIPT')->where('source_id', 2)->sole();
        $this->assertSame('0.0000', $layerA->qty_remaining);
        $this->assertSame('0.0000', $layerB->qty_remaining);

        $destinationLayers = StockLayer::query()->where('source_type', 'TEST_TRANSFER')->where('source_id', 3)->orderBy('source_line_no')->get();
        $this->assertSame(3, $destinationLayers->count());
        [$componentFromA, $componentFromB, $newComponent] = $destinationLayers->all();
        $this->assertSame('1.0000', $componentFromA->qty_remaining);
        $this->assertSame('1.0000', $componentFromB->qty_remaining);
        $this->assertSame('4.0000', $newComponent->qty_received);
        $this->assertSame('4.0000', $newComponent->qty_remaining);
        $this->assertSame('20.0000', $newComponent->base_unit_cost);
        // Total at the destination is conserved at the transfer's own qty.
        $this->assertTrue($destinationLayers->reduce(fn (BigDecimal $sum, StockLayer $l): BigDecimal => $sum->plus($l->qty_remaining), BigDecimal::zero())->isEqualTo('6.0000'));

        $reversal = StockLayerConsumption::query()->where('stock_move_id', $transfer->id)
            ->where('purpose', 'REVERSAL')->where('stock_layer_id', $componentFromA->id)->sole();
        $this->assertSame('4.0000', $reversal->qty_consumed);
        $this->assertSame($request->id, $reversal->recalculation_request_id);
    }

    public function test_outbound_execution_is_idempotent(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '2', '2026-01-10', 2);
        $request = $this->request($a, '3', '2026-01-05');
        $version = $this->approveFully($request->impactVersions->sole());
        $actor = $this->executor();

        $first = app(ExecuteRecalculation::class)($version, $actor);
        $second = app(ExecuteRecalculation::class)($version->fresh(), $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockMove::query()->where('source_type', 'TEST_PROPOSED')->where('source_id', 100)->count());
    }
}
