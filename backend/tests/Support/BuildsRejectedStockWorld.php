<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;

/**
 * The XYZ Paints world of the rejected-stock exit tests (ADR 0097): 20 L usable (3 cans + 5 L loose) on grouped tracking, a QA-required delivery of 12 L
 * (2 cans + 2 L loose) of which 2 L is passed, one can rejected and one can still held. Shared by the exit core and the document/API tests.
 */
trait BuildsRejectedStockWorld
{
    /** @var array<string, mixed> */
    private array $world = [];

    private function grouped(ValuationMethod $method = ValuationMethod::Fifo): void
    {
        [$order, $item, $unit] = $this->issuedOrder($method, qty: '60.0000', qaRequired: true);
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.activate',
            'purchasing.quality-disposition.post']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $count = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $a, 'request_key' => 'xyz-count', 'reason' => 'Opening count', 'expected_quantity' => '20',
            'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(), 'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']],
        ], $actor);
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'activate', 'reason' => 'Start', 'checkpoints' => [$count->id]], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'can' => $can, 'actor' => $actor, 'order' => $order, 'exiter' => $this->exiter()];
    }

    private function exiter(): User
    {
        return PurchasingActor::make(['purchasing.supplier-return.post', 'inventory.scrap.post', 'inventory.package-state.view']);
    }

    private function receipt(string $qty, ?array $composition = null): GoodsReceipt
    {
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->world['order']->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $this->world['order']->lines()->sole()->id, 'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'],
                'received_qty' => $qty, 'received_uom_id' => $this->world['unit']->id]],
        ], $this->world['actor']);
        if ($composition !== null) {
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), $composition, 'Counted on arrival', $this->world['actor']);
        }

        return app(PostGoodsReceipt::class)($receipt, $this->world['actor']);
    }

    /** @param list<array<string,mixed>> $portion */
    private function dispose(GoodsReceipt $receipt, string $outcome, string $qty, array $portion = []): void
    {
        $attributes = ['disposition' => $outcome, 'qty' => $qty, 'reason' => 'Inspected'];
        if ($portion !== []) {
            $attributes['package_portion'] = $portion;
        }
        app(PostQualityDisposition::class)($receipt->lines()->sole(), $attributes, $this->world['actor']->id);
        // Each disposition commits in its own transaction in production; prove its commit-time checks before the next one lands.
        $this->flush();
    }

    /** The 12 L delivery: 2 L passed (usable), one can rejected for return, one can still held. */
    private function mixedDelivery(string $rejectOutcome = 'RETURN_TO_SUPPLIER'): GoodsReceipt
    {
        $can = $this->world['can']->id;
        $receipt = $this->receipt('12', [['packaging_id' => $can, 'count' => 2], ['quantity' => '2']]);
        $this->dispose($receipt, 'PASS', '2', [['kind' => 'LOOSE', 'quantity' => '2']]);
        $this->dispose($receipt, $rejectOutcome, '5', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]]);

        return $receipt;
    }

    /**
     * @param  list<array<string,mixed>>  $portion
     * @return array<string,mixed>
     */
    private function exitInput(GoodsReceipt $receipt, string $kind, string $qty, array $portion = [], string $key = 'exit-1', array $extra = []): array
    {
        return array_merge(['kind' => $kind, 'goods_receipt_line_id' => $receipt->lines()->sole()->id, 'quantity' => $qty, 'request_key' => $key, 'reason' => 'Rejected on inspection', 'portion' => $portion], $extra);
    }

    /** @return array<string,mixed> */
    private function balance(): array
    {
        return collect(app(ReadPackageBalances::class)($this->world['item']->id, $this->world['a'], null, $this->world['actor']))->sole();
    }

    private function flush(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function refused(callable $call, ?string $code = null): DomainInvariantException
    {
        try {
            $call();
        } catch (DomainInvariantException $error) {
            if ($code !== null) {
                $this->assertSame($code, $error->reasonCode, $error->getMessage());
            }

            return $error;
        }
        $this->fail('The operation should have been refused.');
    }
}
