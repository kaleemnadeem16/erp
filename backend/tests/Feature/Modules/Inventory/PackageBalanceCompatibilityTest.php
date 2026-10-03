<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0082 compatibility spike: exercises the real movement boundary before adding a package
 * schema. No simulated package balance is advertised as implementation. The inherited stock
 * baseline also runs, because layer/QA/shortage semantics are prerequisites for the adapter.
 */
class PackageBalanceCompatibilityTest extends StockIssueTest
{
    public function test_a_whole_package_can_span_cost_layers_at_a_counted_activation_checkpoint(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '20');
        $location = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '1', '10', 85001);
        $this->travel(1)->second();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '19', '20', 85002);
        // A counted state of four sealed 5-unit packages is valid at total 20, despite cost layers
        // 1 + 19. One package cannot be represented by a single layer_id with count × factor.
        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '5', 85003));
        $parts = StockLayerConsumption::query()->where('stock_move_id', $move->id)->orderBy('stock_layer_id')->get();
        $this->assertSame(['1.0000', '4.0000'], $parts->pluck('qty_consumed')->all());
        $this->assertSame('-90.0000', (string) $move->valuationEntries()->sum('base_amount'));
        $this->assertSame('15.0000', (string) StockLayer::query()->sum('qty_remaining'));
    }

    public function test_fifo_physical_selection_must_follow_the_actual_cost_layer_allocation(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '4');
        $location = $this->locationId();
        $first = $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '2', '10', 81001);
        $this->travel(1)->second();
        $second = $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '2', '20', 81002);
        $oldLayer = StockLayer::query()->where('source_id', $first->source_id)->sole();
        $newLayer = StockLayer::query()->where('source_id', $second->source_id)->sole();
        // A package choice from the newer receipt cannot be grafted onto this scalar FIFO issue.
        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '1', 81003));
        $this->assertSame($oldLayer->id, StockLayerConsumption::query()->where('stock_move_id', $move->id)->sole()->stock_layer_id);
        $this->assertSame('-10.0000', $move->valuationEntries()->sole()->base_amount);
        $this->assertSame('1.0000', $oldLayer->refresh()->qty_remaining);
        $this->assertSame('2.0000', $newLayer->refresh()->qty_remaining);
    }

    public function test_outer_transaction_can_roll_back_receipt_transfer_and_valuation_together(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Avco);
        $from = $this->locationId();
        $to = $this->otherLocationId('package-destination');
        $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '4', '10', 82001);
        $beforeMoves = StockMove::query()->count();
        $beforeLayers = StockLayer::query()->orderBy('id')->get()->toArray();
        $beforePool = AvcoPool::query()->sole()->getAttributes();
        try {
            DB::transaction(function () use ($item, $unit, $from, $to, $order): void {
                $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '2', '15', 82003);
                app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $unit->id, $from, $to, '2', 82002));
                // Represents a package reconciliation failure in the future adapter.
                throw new DomainInvariantException('Observed package composition does not reconcile.');
            });
            $this->fail('Expected reconciliation failure');
        } catch (DomainInvariantException $exception) {
            $this->assertStringContainsString('does not reconcile', $exception->getMessage());
        }
        $this->assertSame($beforeMoves, StockMove::query()->count());
        $this->assertSame($beforeLayers, StockLayer::query()->orderBy('id')->get()->toArray());
        $this->assertSame($beforePool, AvcoPool::query()->sole()->getAttributes());
    }

    public function test_partial_qa_release_is_layer_bound_and_does_not_identify_which_package_opened(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Avco, qty: '4', qaRequired: true);
        $location = $this->locationId();
        $receiptId = $this->postReceipt($order->lines->sole()->id, $item->id, $unit->id, $location, '4', '10');
        $receipt = GoodsReceipt::query()->findOrFail($receiptId);
        app(PostQualityDisposition::class)($receipt->lines->sole(), ['disposition' => 'PASS', 'qty' => '1', 'reason' => 'Only one stock unit released.'], $this->purchasingActor()->id, 'package-spike-qa');
        // Four physical units might be two sealed 2-unit boxes. PASS 1 alone cannot say which box opened.
        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '1', 83001));
        $this->assertSame('1.0000', $move->qty);
        $this->assertSame('3.0000', StockLayer::query()->sole()->qty_remaining);
        try {
            app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '1', 83002));
            $this->fail('Held quantity must remain unissuable');
        } catch (DomainInvariantException) {
            $this->assertSame('3.0000', StockLayer::query()->sole()->qty_remaining);
        }
    }

    public function test_receipt_reversal_rejects_a_consumed_source_even_when_other_stock_covers_it(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '8');
        $location = $this->locationId();
        $receiptId = $this->postReceipt($order->lines->sole()->id, $item->id, $unit->id, $location, '4', '10');
        $this->travel(1)->second();
        $this->postReceipt($order->lines->sole()->id, $item->id, $unit->id, $location, '4', '20');
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '1', 84001));
        $before = StockMove::query()->count();
        try {
            app(ReverseGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($receiptId), 'Package spike reversal', $this->purchasingActor()->id);
            $this->fail('Consumed source must not be substituted with newer stock');
        } catch (DomainInvariantException) {
            $this->assertSame('POSTED', GoodsReceipt::query()->findOrFail($receiptId)->status->value);
            $this->assertSame($before, StockMove::query()->count());
            $this->assertSame('7.0000', (string) StockLayer::query()->sum('qty_remaining'));
        }
    }
}
