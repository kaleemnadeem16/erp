<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

class OutboundRecalculationReviewTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    public function test_destination_change_stales_an_impact_even_when_the_transfer_net_delta_stays_zero(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '20', '2026-01-10', 2);
        $request = $this->request($a, '2', '2026-01-05', $b);
        $version = $request->impactVersions->sole();
        $this->receive($b, '1', '20', '2026-01-11', 3);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.approve');
        try {
            app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'checked', $actor);
            $this->fail('The destination changed.');
        } catch (DomainInvariantException) {
        }
        $this->assertTrue($version->fresh()->is_stale);
        $this->assertSame('0.0000', $request->currentImpactVersion()->valuation_adjustment);
        $this->assertSame(2, $request->currentImpactVersion()->version_no);
    }

    public function test_unrelated_warehouse_activity_does_not_stale_the_transfer_and_jsonb_roundtrips(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $a = $this->location('a');
        $b = $this->location('b');
        $c = $this->location('c');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '20', '2026-01-10', 2);
        $request = $this->request($a, '2', '2026-01-05', $b);
        $this->receive($c, '1', '30', '2026-01-11', 3);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.approve');
        $approval = app(DecideRecalculationApproval::class)($request->impactVersions->sole()->fresh(), RecalculationApprovalDecision::Approved, 'checked', $actor);
        $this->assertSame(1, $request->impactVersions()->count());
        $this->assertSame(RecalculationApprovalDecision::Approved, $approval->decision);
    }

    public function test_zero_net_same_pool_transfer_still_uses_gross_exposure_for_risk(): void
    {
        $this->fixture(ValuationMethod::Avco);
        Company::findOrFail(1)->update(['recalculation_high_risk_threshold_base_amount' => '15']);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '20', '2026-01-10', 2);
        $version = $this->request($a, '2', '2026-01-05', $b)->impactVersions->sole();
        $this->assertSame('0.0000', $version->valuation_adjustment);
        $this->assertSame('HIGH_RISK', $version->risk_classification->value);
    }

    public function test_company_batch_pool_excludes_other_batches(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::CompanyItemBatch);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1, batch: 'batch-a');
        $this->receive($a, '5', '30', '2026-01-02', 2, batch: 'batch-b');
        $this->issue($a, '1', '2026-01-10', 3, batch: 'batch-a');
        $version = $this->request($a, '2', '2026-01-05', $b, batch: 'batch-a')->impactVersions->sole();
        $this->assertSame('40.0000', $version->original_inventory_value);
        $this->assertSame('0.0000', $version->valuation_adjustment);
        $this->assertCount(1, $version->movement_impact['avco_pool_ids']);
        $this->assertCount(2, $version->movement_impact['movement_ids']);
    }

    public function test_company_pool_cost_includes_other_batches_but_physical_issue_does_not(): void
    {
        $this->fixture(ValuationMethod::Avco, tracking: 'BATCH');
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1, batch: 'batch-a');
        $this->receive($a, '5', '30', '2026-01-02', 2, batch: 'batch-b');
        $this->issue($a, '1', '2026-01-10', 3, batch: 'batch-b');
        $version = $this->request($a, '1', '2026-01-05', batch: 'batch-a')->impactVersions->sole();
        $this->assertSame('20.0000', $version->movement_impact['proposed']['base_amount']);
        $this->assertSame('8.0000', $version->counterfactual_qty_on_hand);
    }

    public function test_outbound_request_refuses_a_caller_supplied_cost(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '1', '2026-01-10', 2);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('derived from historical stock');
        app(RequestStockRecalculation::class)([
            'movement_kind' => 'ISSUE', 'item_id' => $this->item->id, 'location_id' => $a->id,
            'qty' => '1', 'unit_cost' => '0.0001', 'moved_at' => '2026-01-05', 'source_type' => 'TEST_PROPOSED', 'source_id' => 100,
        ], $this->requester);
    }

    public function test_avco_exact_receipt_reversal_is_not_repriced_as_an_issue(): void
    {
        $this->fixture(ValuationMethod::Avco);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '30', '2026-01-02', 2);
        app(PostInventoryMovement::class)->reverseReceipt('TEST_RECEIPT', 2, 'TEST_REVERSE', 3, '2026-01-10');
        $version = $this->request($a, '1', '2026-01-05')->impactVersions->sole();
        $this->assertSame('30.0000', $version->counterfactual_inventory_value);
        $reversal = StockMove::where('source_type', 'TEST_REVERSE')->sole();
        $this->assertNotNull($reversal->reverses_move_id);
        $this->assertArrayNotHasKey($reversal->id, $version->movement_impact['changed_movements']);
    }

    public function test_legacy_fifo_outbound_identity_can_be_verified_from_the_immutable_ledger(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '30', '2026-01-02', 2);
        $this->issue($a, '1', '2026-01-10', 3);
        app(PostInventoryMovement::class)->reverseReceipt('TEST_RECEIPT', 2, 'TEST_REVERSE', 4, '2026-01-11');
        // Simulate rows predating the discriminator; recorded economics/consumptions stay intact.
        // The ledger is database-immutable (stock_moves_immutable), so the only way to fake
        // rows that by definition predate that guard is to switch it off for exactly this one
        // statement — never a pattern for application code.
        // Run the commit-time checks queued by the postings above first: PostgreSQL will not ALTER a table that has pending trigger events.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::statement('ALTER TABLE stock_moves DISABLE TRIGGER stock_moves_immutable');
        DB::table('stock_moves')->whereNotNull('from_location_id')->update(['movement_kind' => 'LEGACY', 'reverses_move_id' => null]);
        DB::statement('ALTER TABLE stock_moves ENABLE TRIGGER stock_moves_immutable');
        $version = $this->request($a, '1', '2026-01-05', $b)->impactVersions->sole();
        $this->assertSame('40.0000', $version->counterfactual_inventory_value);
        $this->assertSame(2, StockMove::where('movement_kind', 'LEGACY')->count());
    }
}
