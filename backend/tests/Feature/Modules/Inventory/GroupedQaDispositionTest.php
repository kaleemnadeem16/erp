<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\IssueGroupedStock;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\QualityDisposition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

/**
 * ADR 0094: an inspection-required delivery of a grouped item arrives HELD; a QA disposition relabels a declared physical portion usable or rejected.
 * XYZ Paints: 3 cans + 5 L loose usable (20 L); a QA-required delivery of 2 cans + 2 L loose (12 L) arrives held at the same location.
 */
class GroupedQaDispositionTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $world = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function world(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '60.0000', qaRequired: true);
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.activate',
            'inventory.package-state.reverse', 'purchasing.quality-disposition.post', 'purchasing.goods-receipt.reverse']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $count = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $a, 'request_key' => 'xyz-count', 'reason' => 'Opening count', 'expected_quantity' => '20',
            'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(), 'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']],
        ], $actor);
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'activate', 'reason' => 'Start', 'checkpoints' => [$count->id]], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'can' => $can, 'actor' => $actor, 'order' => $order];
    }

    /** The QA-required delivery: 2 cans + 2 L loose, posted held. */
    private function heldReceipt(): GoodsReceipt
    {
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->world['order']->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $this->world['order']->lines()->sole()->id, 'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'],
                'received_qty' => '12', 'received_uom_id' => $this->world['unit']->id]],
        ], $this->world['actor']);
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']], 'Counted on arrival', $this->world['actor']);

        return app(PostGoodsReceipt::class)($receipt, $this->world['actor']);
    }

    /** @return array<string,mixed> */
    private function balance(): array
    {
        return collect(app(ReadPackageBalances::class)($this->world['item']->id, $this->world['a'], null, $this->world['actor']))->sole();
    }

    /** @param list<array<string,mixed>> $portion */
    private function dispose(GoodsReceipt $receipt, string $outcome, string $qty, array $portion, ?string $key = null): QualityDisposition
    {
        return app(PostQualityDisposition::class)($receipt->lines()->sole(), ['disposition' => $outcome, 'qty' => $qty, 'reason' => 'Inspected', 'package_portion' => $portion], $this->world['actor']->id, $key);
    }

    /** @return array<string,string> held contribution per layer of the latest evidence */
    private function heldAtReceiptLayer(GoodsReceipt $receipt): string
    {
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receipt->lines()->sole()->id)->sole();
        $latest = PackageOperation::query()->whereIn('evidence_kind', PackageOperation::CHAIN)->orderByDesc('id')->firstOrFail();
        $states = PackageState::query()->where('operation_id', $latest->id)->where('availability', 'HELD')->pluck('id');

        return (string) PackageContribution::query()->whereIn('state_id', $states)->where('stock_layer_id', $layer->id)->sum('quantity');
    }

    public function test_an_inspection_required_delivery_arrives_held_and_cannot_be_issued_until_released(): void
    {
        $this->world();
        $this->heldReceipt();
        $balance = $this->balance();

        $this->assertSame(['32.0000', '20.0000', '5.0000', '2.0000'], [$balance['total'], $balance['usable_total'], $balance['loose'], $balance['held']['loose']]);
        $this->assertSame([3, 2], [$balance['sealed'][0]['count'], $balance['held']['sealed'][0]['count']]);
        $this->assertSame('12.0000', $balance['held']['total']);
        $this->assertSame('0.0000', $balance['rejected']['total']);

        $this->expectException(DomainInvariantException::class);
        app(IssueGroupedStock::class)([
            'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'], 'request_key' => 'too-much', 'reason' => 'Job',
            'draw' => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 4]],
        ], $this->world['actor']);
    }

    public function test_pass_releases_a_declared_portion_and_rejection_marks_the_rest_without_moving_stock_or_cost(): void
    {
        $this->world();
        $receipt = $this->heldReceipt();
        $moves = StockMove::query()->count();
        $layers = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all();

        // Pass 1 sealed can and the 2 L loose (7 L): usable now.
        $pass = $this->dispose($receipt, 'PASS', '7', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1], ['kind' => 'LOOSE', 'quantity' => '2']], 'qa-1');
        $balance = $this->balance();
        $this->assertSame([4, '7.0000', '27.0000', 1, '0.0000', '5.0000'], [$balance['sealed'][0]['count'], $balance['loose'], $balance['usable_total'], $balance['held']['sealed'][0]['count'], $balance['held']['loose'], $balance['held']['total']]);
        $this->assertSame('5.0000', $this->heldAtReceiptLayer($receipt));
        $evidence = PackageOperation::query()->where('quality_disposition_id', $pass->id)->sole();
        $this->assertSame('QA_DISPOSITION', $evidence->evidence_kind);
        $this->assertNull($evidence->stock_move_id);
        $this->assertSame($moves, StockMove::query()->count(), 'a disposition posts no stock movement');
        $this->assertSame($layers, StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(), 'and changes no layer');

        // Released stock is issuable: 22 L takes the older 20 L and 2 L of the released delivery; the still-held can stays held.
        app(IssueGroupedStock::class)([
            'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'], 'request_key' => 'use-released', 'reason' => 'Job',
            'draw' => [['kind' => 'LOOSE', 'quantity' => '7'], ['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 3]],
        ], $this->world['actor']);
        $this->assertSame('5.0000', $this->heldAtReceiptLayer($receipt));

        // Scrap the last held can: rejected, still on hand, never usable.
        $this->dispose($receipt, 'SCRAP', '5', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]], 'qa-2');
        $balance = $this->balance();
        $this->assertSame(['0.0000', '5.0000', 1], [$balance['held']['total'], $balance['rejected']['total'], $balance['rejected']['sealed'][0]['count']]);
        $this->assertSame(0, bccomp('0', $this->heldAtReceiptLayer($receipt), 4));
        $this->assertSame((string) bcadd($balance['usable_total'], '5.0000', 4), $balance['total']);
    }

    public function test_the_portion_is_required_exact_and_whole_and_a_failed_disposition_leaves_nothing(): void
    {
        $this->world();
        $receipt = $this->heldReceipt();
        $can = $this->world['can']->id;
        $before = [QualityDisposition::query()->count(), PackageOperation::query()->count()];

        foreach ([
            'missing portion' => [[], 'NEEDS_DECLARED_PORTION'],
            'adds up to the wrong quantity' => [[['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]], null],
            'loose beyond what is held (part of a sealed can)' => [[['kind' => 'LOOSE', 'quantity' => '3']], 'DRAW_NOT_ON_HAND'],
            'opens a held can' => [[['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can, 'count' => 1, 'quantity' => '2']], null],
        ] as $label => [$portion, $code]) {
            try {
                $this->dispose($receipt, 'PASS', str_starts_with($label, 'loose beyond') ? '3' : ($label === 'opens a held can' ? '2' : '4'), $portion);
                $this->fail("{$label} must be refused");
            } catch (DomainInvariantException $error) {
                if ($code !== null) {
                    $this->assertSame($code, $error->reasonCode, $label);
                }
            }
        }
        $this->assertSame($before, [QualityDisposition::query()->count(), PackageOperation::query()->count()], 'every refusal rolled the disposition back');
        $this->assertSame('12.0000', $this->balance()['held']['total']);

        // A non-deciding outcome leaves the packages held and needs no portion.
        app(PostQualityDisposition::class)($receipt->lines()->sole(), ['disposition' => 'REWORK', 'qty' => '5', 'reason' => 'Rework'], $this->world['actor']->id);
        $this->assertSame('12.0000', $this->balance()['held']['total']);
        $this->assertSame($before[1], PackageOperation::query()->count());
    }

    public function test_a_retry_is_idempotent_and_the_database_requires_evidence_for_a_deciding_disposition(): void
    {
        $this->world();
        $receipt = $this->heldReceipt();
        $portion = [['kind' => 'LOOSE', 'quantity' => '2']];
        $first = $this->dispose($receipt, 'PASS', '2', $portion, 'same-key');
        $again = $this->dispose($receipt, 'PASS', '2', $portion, 'same-key');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, PackageOperation::query()->where('evidence_kind', 'QA_DISPOSITION')->count());

        // Bypassing the Action: a deciding disposition row of a grouped item without evidence cannot commit.
        QualityDisposition::query()->create(['goods_receipt_line_id' => $receipt->lines()->sole()->id, 'disposition' => 'PASS', 'qty' => '1', 'reason' => 'raw',
            'disposed_by' => $this->world['actor']->id, 'disposed_at' => now()]);
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_a_scope_under_qa_cannot_be_counted_and_an_untouched_held_delivery_reverses_from_its_held_packages(): void
    {
        $this->world();
        $receipt = $this->heldReceipt();
        $creator = PurchasingActor::make(['inventory.physical_count.create', 'inventory.package-state.view']);
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => Location::query()->findOrFail($this->world['a'])->warehouse_id], $creator);
        try {
            app(AddPhysicalCountLine::class)($count, ['item_id' => $this->world['item']->id, 'location_id' => $this->world['a']], $creator);
            $this->fail('A scope under QA must not be counted.');
        } catch (DomainInvariantException $error) {
            $this->assertSame('QA_STOCK_HELD', $error->reasonCode);
        }

        app(ReverseGoodsReceipt::class)($receipt, 'Wrong delivery', $this->world['actor']->id);
        $balance = $this->balance();
        $this->assertSame(['20.0000', '0.0000'], [$balance['total'], $balance['held']['total']]);
        $this->assertSame([3, '5.0000'], [$balance['sealed'][0]['count'], $balance['loose']]);
    }

    public function test_a_reversal_is_refused_once_qa_has_decided_anything(): void
    {
        $this->world();
        $receipt = $this->heldReceipt();
        $this->dispose($receipt, 'PASS', '2', [['kind' => 'LOOSE', 'quantity' => '2']]);

        $this->expectException(DomainInvariantException::class);
        app(ReverseGoodsReceipt::class)($receipt, 'Wrong delivery', $this->world['actor']->id);
    }
}
