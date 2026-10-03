<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptPackageObservation;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReceiptPackageEvidenceTest extends StockIssueTest
{
    public function test_observed_sealed_and_loose_contents_preserve_the_commercial_agreement(): void
    {
        [$receipt, $actor, $pack] = $this->fixture();
        $line = $receipt->lines()->sole();
        $commercial = [$line->received_qty, $line->provisional_amount, $line->item_packaging_id];
        app(RecordReceiptComposition::class)($line, [['packaging_id' => $pack->id, 'count' => 1], ['quantity' => '2']], 'One sealed pack plus loose delivery', $actor);
        $this->assertSame(0, PackageOperation::query()->count());
        app(PostGoodsReceipt::class)($receipt, $actor);
        $operation = PackageOperation::query()->sole();
        $this->assertSame('RECEIPT', $operation->evidence_kind);
        $this->assertSame($line->id, $operation->source_receipt_line_id);
        $this->assertSame($commercial, [$line->refresh()->received_qty, $line->provisional_amount, $line->item_packaging_id]);
        $this->assertSame(['SEALED', 'OPEN_LOOSE'], PackageState::query()->orderBy('id')->pluck('form')->all());
        $this->assertSame(['2.0000', '2.0000'], PackageContribution::query()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame('4.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertSame('40.0000', StockMove::query()->sole()->valuationEntry->base_amount);
    }

    public function test_unobserved_receipt_does_not_invent_package_state(): void
    {
        [$receipt, $actor] = $this->fixture();
        app(PostGoodsReceipt::class)($receipt, $actor);
        $this->assertSame(0, PackageOperation::query()->count());
        $this->assertSame('4.0000', StockLayer::query()->sole()->qty_remaining);
    }

    public function test_draft_observation_can_be_replaced_without_posting_inventory(): void
    {
        [$receipt, $actor, $pack] = $this->fixture();
        $line = $receipt->lines()->sole();
        app(RecordReceiptComposition::class)($line, [['packaging_id' => $pack->id, 'count' => 2]], 'Initial count', $actor);
        app(RecordReceiptComposition::class)($line, [['quantity' => '4']], 'Corrected: all delivered open', $actor);
        $this->assertSame('OPEN_LOOSE', GoodsReceiptPackageObservation::query()->sole()->form);
        $this->assertSame('Corrected: all delivered open', GoodsReceiptPackageObservation::query()->sole()->reason);
        $this->assertSame(0, StockMove::query()->count());
    }

    public function test_observation_requires_receipt_creation_authority(): void
    {
        [$receipt] = $this->fixture();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('creation authority');
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['quantity' => '4']], 'Observed', User::factory()->create());
    }

    public function test_mismatched_observation_is_refused_before_any_draft_rows_are_written(): void
    {
        [$receipt, $actor] = $this->fixture();
        try {
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['quantity' => '3']], 'Wrong total', $actor);
            $this->fail('Expected mismatch.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('must equal', $error->getMessage());
        }
        $this->assertSame(0, GoodsReceiptPackageObservation::query()->count());
        $this->assertSame(0, StockMove::query()->count());
    }

    public function test_stale_composition_failure_rolls_back_stock_layers_pool_and_receipt_posting(): void
    {
        [$receipt, $actor] = $this->fixture();
        $line = $receipt->lines()->sole();
        app(RecordReceiptComposition::class)($line, [['quantity' => '4']], 'Observed', $actor);
        $line->update(['stock_qty' => '3']); // Simulate a draft edit after the observation.
        try {
            app(PostGoodsReceipt::class)($receipt, $actor);
            $this->fail('Expected reconciliation failure.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('does not reconcile', $error->getMessage());
        }
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->refresh()->status);
        $this->assertSame(0, StockMove::query()->count());
        $this->assertSame(0, StockLayer::query()->count());
        $this->assertSame(0, AvcoPool::query()->count());
        $this->assertSame(0, PackageOperation::query()->count());
    }

    public function test_qa_required_receipt_captures_held_physical_evidence(): void
    {
        [$receipt, $actor, $pack] = $this->fixture(true);
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $pack->id, 'count' => 2]], 'Delivered sealed for QA', $actor);
        app(PostGoodsReceipt::class)($receipt, $actor);
        $this->assertSame('HELD', PackageState::query()->sole()->availability);
        $this->assertSame('SEALED', PackageState::query()->sole()->form);
    }

    public function test_posting_retry_does_not_duplicate_receipt_evidence(): void
    {
        [$receipt, $actor] = $this->fixture();
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['quantity' => '4']], 'Observed', $actor);
        app(PostGoodsReceipt::class)($receipt, $actor);
        $id = PackageOperation::query()->sole()->id;
        app(PostGoodsReceipt::class)($receipt, $actor);
        $this->assertSame($id, PackageOperation::query()->sole()->id);
        $this->assertSame(1, PackageState::query()->count());
    }

    public function test_posted_observation_is_immutable_in_raw_sql(): void
    {
        [$receipt, $actor] = $this->fixture();
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['quantity' => '4']], 'Observed', $actor);
        app(PostGoodsReceipt::class)($receipt, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');
        DB::table('goods_receipt_package_observations')->update(['reason' => 'rewrite']);
    }

    public function test_negative_debt_settlement_is_refused_and_rolled_back_for_observed_receipts(): void
    {
        [$receipt, $actor] = $this->fixture();
        $line = $receipt->lines()->sole();
        $this->directReceipt($line->item_id, $line->stock_uom_id, $line->location_id, $line->currency_id, '1', '10', 99801);
        $override = $this->approvedOverride($line->item_id, $line->location_id, '2', 99802);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($line->item_id, $line->stock_uom_id, $line->location_id, '2', 99802),
            ['override_request_id' => $override->id],
        ));
        app(RecordReceiptComposition::class)($line, [['quantity' => '4']], 'Observed', $actor);
        $moves = StockMove::query()->count();
        try {
            app(PostGoodsReceipt::class)($receipt, $actor);
            $this->fail('Expected debt adapter rejection.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('debt settlement', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame('1.0000', NegativeStockShortage::query()->sole()->qty_open);
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->refresh()->status);
    }

    public function test_reversal_keeps_receipt_composition_as_historical_evidence(): void
    {
        [$receipt, $actor, $pack] = $this->fixture();
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $pack->id, 'count' => 2]], 'Observed sealed', $actor);
        app(PostGoodsReceipt::class)($receipt, $actor);
        $before = PackageState::query()->sole()->getAttributes();
        app(ReverseGoodsReceipt::class)($receipt, 'Return entire unused receipt', $actor->id);
        $this->assertSame(GoodsReceiptStatus::Reversed, $receipt->refresh()->status);
        $this->assertSame($before, PackageState::query()->sole()->getAttributes());
        $this->assertSame('0.0000', StockLayer::query()->sole()->qty_remaining);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_finer_than_stock_precision_is_not_silently_rounded(): void
    {
        [$receipt, $actor] = $this->fixture();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('exact at stock precision');
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['quantity' => '4.00001']], 'Observed', $actor);
    }

    private function fixture(bool $qa = false): array
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Avco, qaRequired: $qa);
        $actor = $this->purchasingActor();
        $pack = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'observed-two', 'name' => 'Observed 2 units', 'stock_quantity' => '2', 'active' => true]);
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $order->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->sole()->id, 'item_id' => $item->id,
                'location_id' => $this->locationId(), 'received_qty' => '4', 'received_uom_id' => $unit->id,
            ]],
        ], $actor);

        return [$receipt, $actor, $pack];
    }
}
