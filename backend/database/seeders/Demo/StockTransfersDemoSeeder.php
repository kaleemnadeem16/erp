<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use Carbon\CarbonInterface;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Concerns\SeedsDedicatedStockItems;
use Illuminate\Database\Seeder;

/**
 * Stock transfers between the two demo warehouses (Main Stock and Karachi Stock).
 * Nothing seeded elsewhere ever moves stock between locations, so the ledger's
 * transfer rows, AVCO pool identities on a transfer and FIFO layers moving with
 * their stock were invisible. Each scenario shows one of the three ways a
 * transfer treats value (docs/domain/stock.md, "Transfers"), on a dedicated
 * item so a transfer's pools and layers stay independent of everything else:
 *
 *   A. `demo-xfer-emulsion` — AVCO, one company-wide pool. Custody moves; the pool's
 *      quantity, value and average do not, because both locations belong to the same pool.
 *   B. `demo-xfer-varnish` — AVCO, one pool per warehouse. Value moves between two pools at
 *      the source pool's average and the destination's average is recalculated; company
 *      quantity and value are conserved. Karachi already holds stock at a different cost, so
 *      the blend is visible.
 *   C. `demo-xfer-lacquer` — FIFO. Each source layer's cost and receipt date travel with the
 *      stock, so the destination gets one layer per source-cost component that keeps its
 *      original age.
 *
 *   D. `demo-xfer-laser` — SPECIFIC, serial-tracked, on its own item (the only other serial item is shared
 *      with other seeded data, and moving one of its units would change stock those scenarios rely on).
 *      Three meters are received at Main, each at its own cost; one serial is sent to Karachi and arrives
 *      there as that exact unit at its own cost — a SPECIFIC transfer moves the identified unit, not an average.
 *
 * Idempotency: a transfer, like every stock move, is immutable, so each scenario is created
 * once and recognised-and-skipped by its transfer's natural key rather than recomputed from
 * live stock (docs/architecture/environments.md, "Immutable transactional demo data").
 */
final class StockTransfersDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;
    use SeedsDedicatedStockItems;

    private const TRANSFER = 'DEMO_TRANSFER';

    private const RECEIPT = 'DEMO_TRANSFER_RECEIPT';

    private Location $main;

    private Location $karachi;

    private Currency $currency;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $this->main = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $this->karachi = Warehouse::query()->where('code', 'demo-khi')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $this->currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        $this->seedSamePoolTransfer();
        $this->seedCrossPoolTransfer();
        $this->seedFifoTransfer();
        $this->seedSerialTransfer();
    }

    /** A: 60 @80 at Main, 25 sent to Karachi — same pool, nothing about value changes. */
    private function seedSamePoolTransfer(): void
    {
        if ($this->exists(1)) {
            return;
        }

        $item = $this->dedicatedStockItem(
            'demo-xfer-emulsion', 'Matt Emulsion Base',
            'Dedicated to the stock transfer demo. Its AVCO pool spans the whole company, so moving 25 kg from Main Stock to Karachi Stock changes where the stock is but not the pool\'s quantity, value or average.',
        );
        $this->postReceipt($item, $this->main, $this->currency, '60.0000', '80.0000', self::RECEIPT, 1, now()->subDays(20));
        $this->transfer($item, '25.0000', 1, now()->subDays(5));
    }

    /** B: Main 100 @50, Karachi 20 @60 (each its own pool), 40 sent — Karachi's pool re-averages. */
    private function seedCrossPoolTransfer(): void
    {
        if ($this->exists(2)) {
            return;
        }

        $item = $this->dedicatedStockItem(
            'demo-xfer-varnish', 'Polyurethane Varnish',
            'Dedicated to the stock transfer demo. Each warehouse keeps its own AVCO pool: 40 kg moved from Main Stock (100 kg @ 50) to Karachi Stock (20 kg @ 60) takes value at Main\'s average and re-averages Karachi\'s pool, while company quantity and value stay the same.',
            scope: AvcoPoolScope::WarehouseItem,
        );
        $this->postReceipt($item, $this->main, $this->currency, '100.0000', '50.0000', self::RECEIPT, 2, now()->subDays(20));
        $this->postReceipt($item, $this->karachi, $this->currency, '20.0000', '60.0000', self::RECEIPT, 3, now()->subDays(18));
        $this->transfer($item, '40.0000', 2, now()->subDays(5));
    }

    /** C: FIFO layers 10 @90 then 10 @110 at Main; 15 sent, spanning both, so Karachi gets 10 @90 and 5 @110. */
    private function seedFifoTransfer(): void
    {
        if ($this->exists(3)) {
            return;
        }

        $item = $this->dedicatedStockItem(
            'demo-xfer-lacquer', 'Clear Lacquer',
            'Dedicated to the stock transfer demo. FIFO: a 15 L transfer to Karachi Stock consumes the older 10 L @ 90 layer and 5 L of the newer @ 110 layer, and each destination layer keeps its source layer\'s cost and receipt date.',
            method: ValuationMethod::Fifo, scope: null, uomCode: 'l',
        );
        $this->postReceipt($item, $this->main, $this->currency, '10.0000', '90.0000', self::RECEIPT, 4, now()->subDays(20));
        $this->postReceipt($item, $this->main, $this->currency, '10.0000', '110.0000', self::RECEIPT, 5, now()->subDays(10));
        $this->transfer($item, '15.0000', 3, now()->subDays(5));
    }

    /** D: three serial-numbered meters at Main (400, 450, 500 each); the one costing 450 goes to Karachi. */
    private function seedSerialTransfer(): void
    {
        if ($this->exists(4)) {
            return;
        }

        $item = $this->dedicatedStockItem(
            'demo-xfer-laser', 'Laser Distance Meter',
            'Dedicated to the stock transfer demo. SPECIFIC valuation, tracked by serial number: SN-LM-002 (cost 450) was transferred from Main Stock to Karachi Stock and arrives as that exact unit at its own cost, while SN-LM-001 and SN-LM-003 stay at Main.',
            categoryCode: 'demo-equipment', method: ValuationMethod::Specific, scope: null, uomCode: 'ea', tracking: ItemTracking::Serial,
        );
        foreach ([['SN-LM-001', '400.0000', 20, 6], ['SN-LM-002', '450.0000', 19, 7], ['SN-LM-003', '500.0000', 18, 8]] as [$serial, $cost, $daysAgo, $sourceId]) {
            $this->postReceipt($item, $this->main, $this->currency, '1.0000', $cost, self::RECEIPT, $sourceId, now()->subDays($daysAgo), $serial);
        }
        $this->transfer($item, '1.0000', 4, now()->subDays(5), 'SN-LM-002');
    }

    private function exists(int $sourceId): bool
    {
        return StockMove::query()->where('source_type', self::TRANSFER)->where('source_id', $sourceId)->exists();
    }

    private function transfer(Item $item, string $qty, int $sourceId, CarbonInterface $movedAt, ?string $serialNo = null): void
    {
        app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $this->main->id, 'to_location_id' => $this->karachi->id,
            'qty' => $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $movedAt,
            'source_type' => self::TRANSFER, 'source_id' => $sourceId,
            ...($serialNo === null ? [] : ['serial_no' => $serialNo]),
        ]);
    }
}
