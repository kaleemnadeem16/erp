<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

class OutboundRecalculationTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    public static function methods(): array
    {
        return [[ValuationMethod::Fifo, '80.0000', '40.0000'], [ValuationMethod::Avco, '73.3334', '40.0000']];
    }

    #[DataProvider('methods')]
    public function test_backdated_issue_derives_cost_and_reprices_later_consumption_without_posting(ValuationMethod $method, string $value, string $cost): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($a, '5', '20', '2026-01-10', 2);
        $this->issue($a, '2', '2026-01-12', 3);
        $layers = StockLayer::all()->toArray();
        $pools = AvcoPool::all()->toArray();
        $request = $this->request($a, '4', '2026-01-05');
        $version = $request->impactVersions->sole();
        $this->assertSame('4.0000', $version->counterfactual_qty_on_hand);
        $this->assertSame($value, $version->counterfactual_inventory_value);
        $this->assertSame($cost, $version->movement_impact['proposed']['base_amount']);
        $this->assertNotEmpty($version->movement_impact['changed_movements']);
        $this->assertNull($request->base_unit_cost);
        $this->assertSame($layers, StockLayer::all()->toArray());
        $this->assertSame($pools, AvcoPool::all()->toArray());
        $this->assertSame(3, StockMove::count());
        $this->assertSame($request->id, $this->request($a, '4', '2026-01-05')->id);
    }

    public static function transfers(): array
    {
        return [
            [ValuationMethod::Fifo, AvcoPoolScope::CompanyItem, '180.0000'],
            [ValuationMethod::Avco, AvcoPoolScope::CompanyItem, '160.0000'],
            [ValuationMethod::Avco, AvcoPoolScope::WarehouseItem, '155.0000'],
        ];
    }

    #[DataProvider('transfers')]
    public function test_transfer_replay_carries_cost_through_a_later_transfer_and_issue(ValuationMethod $method, AvcoPoolScope $scope, string $expected): void
    {
        $this->fixture($method, $scope);
        $a = $this->location('a');
        $b = $this->location('b');
        $c = $this->location('c');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '30', '2026-01-02', 2);
        $this->transfer($b, $c, '4', '2026-01-05', 3);
        $this->issue($c, '2', '2026-01-06', 4);
        $layers = StockLayer::all()->toArray();
        $version = $this->request($a, '3', '2026-01-03', $b)->impactVersions->sole();
        $this->assertSame($expected, $version->counterfactual_inventory_value);
        $this->assertSame($version->original_qty_on_hand, $version->counterfactual_qty_on_hand);
        $this->assertSame([$a->id, $b->id, $c->id], $version->movement_impact['location_ids']);
        $this->assertSame($layers, StockLayer::all()->toArray());
        if ($method === ValuationMethod::Avco && $scope === AvcoPoolScope::CompanyItem) {
            $this->assertSame('0.0000', $version->valuation_adjustment);
        }
    }

    public function test_transfer_to_a_new_warehouse_previews_without_creating_a_pool(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '1', '2026-01-10', 2);
        $version = $this->request($a, '2', '2026-01-05', $b)->impactVersions->sole();
        $this->assertSame('0.0000', $version->valuation_adjustment);
        $this->assertSame('20.0000', $version->movement_impact['counterfactual_scopes']['warehouse:'.$b->warehouse_id]['value']);
        $this->assertSame(1, AvcoPool::count());
    }

    public function test_specific_issue_can_exhaust_its_serial_without_touching_other_serials(): void
    {
        $this->fixture(ValuationMethod::Specific);
        $a = $this->location('a');
        $this->receive($a, '1', '10', '2026-01-01', 1, 'S1');
        $this->receive($a, '1', '30', '2026-01-10', 2, 'S2');
        $version = $this->request($a, '1', '2026-01-05', serial: 'S1')->impactVersions->sole();
        $this->assertSame('0.0000', $version->counterfactual_qty_on_hand);
        $this->assertSame('0.0000', $version->counterfactual_inventory_value);
        $this->assertCount(1, $version->movement_impact['movement_ids']);
    }

    public function test_specific_transfer_keeps_the_exact_serial_and_cost(): void
    {
        $this->fixture(ValuationMethod::Specific);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '1', '10', '2026-01-01', 1, 'S1');
        $this->receive($a, '1', '30', '2026-01-10', 2, 'S2');
        $version = $this->request($a, '1', '2026-01-05', $b, 'S1')->impactVersions->sole();
        $this->assertSame('0.0000', $version->valuation_adjustment);
        $this->assertSame('0.0000', $version->movement_impact['counterfactual_scopes']['location:'.$a->id]['qty']);
        $this->assertSame('10.0000', $version->movement_impact['counterfactual_scopes']['location:'.$b->id]['value']);
    }

    #[DataProvider('methods')]
    public function test_proposed_issue_cannot_steal_stock_needed_by_a_later_posted_issue(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '4', '2026-01-10', 2);
        try {
            $this->request($a, '2', '2026-01-05');
            $this->fail('Expected historical insufficiency to block the proposal.');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('insufficient physical stock', $e->getMessage());
        }
        $this->assertSame(0, RecalculationRequest::count());
    }

    #[DataProvider('methods')]
    public function test_complete_counterfactual_drawdown_leaves_no_quantity_or_value_residual(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '2', '2026-01-10', 2);
        $version = $this->request($a, '3', '2026-01-05')->impactVersions->sole();
        $this->assertSame('0.0000', $version->counterfactual_qty_on_hand);
        $this->assertSame('0.0000', $version->counterfactual_inventory_value);
        $this->assertSame('-30.0000', $version->valuation_adjustment);
    }

    public function test_fifo_replay_handles_a_transfer_returning_to_an_earlier_location(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->receive($b, '5', '30', '2026-01-02', 2);
        $this->transfer($b, $a, '3', '2026-01-05', 3);
        $this->issue($a, '2', '2026-01-06', 4);
        $version = $this->request($a, '2', '2026-01-03', $b)->impactVersions->sole();
        $this->assertSame('0.0000', $version->valuation_adjustment);
        $this->assertSame('8.0000', $version->counterfactual_qty_on_hand);
        $move = StockMove::where('source_type', 'TEST_TRANSFER')->sole();
        $this->assertSame('50.0000', $version->movement_impact['changed_movements'][$move->id]['counterfactual']['base_amount']);
    }

    public function test_fractional_avco_transfer_keeps_pool_rate_and_exact_transfer_amount_separate(): void
    {
        $this->fixture(ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '0.3000', '18.3333', '2026-01-01', 1);
        $this->receive($b, '1', '10', '2026-01-10', 2);
        $version = $this->request($a, '0.0001', '2026-01-05', $b)->impactVersions->sole();
        $this->assertSame('0.0018', $version->movement_impact['proposed']['base_amount']);
        $this->assertSame('18.3333', $version->movement_impact['proposed']['base_unit_cost']);
        $this->assertSame('0.0000', $version->valuation_adjustment);
    }
}
