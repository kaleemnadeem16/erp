<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * StockTransfersDemoSeeder claims one transfer for each of the three ways a transfer treats value.
 * Every claim is checked against the stored facts rather than the seeder's own comments.
 */
final class StockTransfersDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_a_same_pool_transfer_moves_custody_but_not_the_pools_value(): void
    {
        $item = $this->item('demo-xfer-emulsion');
        $detail = $this->detail($item);

        // Both locations belong to the one company-wide pool, so source and destination are the same.
        $this->assertNotNull($detail->source_avco_pool_id);
        $this->assertSame($detail->source_avco_pool_id, $detail->destination_avco_pool_id);
        $pool = DB::table('avco_pools')->find($detail->source_avco_pool_id);
        $this->assertSame(['60.0000', '4800.0000', '80.0000'], [$pool->qty_on_hand, $pool->inventory_value, $pool->avg_unit_cost]);

        // Custody moved: 35 at Main, 25 at Karachi.
        $this->assertSame(['35.0000', '25.0000'], [$this->onHand($item, 'demo-main'), $this->onHand($item, 'demo-khi')]);
    }

    public function test_a_cross_pool_transfer_takes_value_at_the_source_average_and_reaverages_the_destination(): void
    {
        $item = $this->item('demo-xfer-varnish');
        $detail = $this->detail($item);

        $this->assertNotSame($detail->source_avco_pool_id, $detail->destination_avco_pool_id);
        $main = DB::table('avco_pools')->find($detail->source_avco_pool_id);
        $karachi = DB::table('avco_pools')->find($detail->destination_avco_pool_id);

        // Main: 100 @50 less 40 at its own average. Karachi: 20 @60 plus 40 @50 → 60 units worth 3,200.
        $this->assertSame(['60.0000', '3000.0000', '50.0000'], [$main->qty_on_hand, $main->inventory_value, $main->avg_unit_cost]);
        $this->assertSame(['60.0000', '3200.0000', '53.3333'], [$karachi->qty_on_hand, $karachi->inventory_value, $karachi->avg_unit_cost]);
        // Company quantity and value are unchanged by the transfer: 100@50 + 20@60.
        $this->assertSame('6200.0000', number_format((float) $main->inventory_value + (float) $karachi->inventory_value, 4, '.', ''));
        $this->assertSame(120.0, (float) $main->qty_on_hand + (float) $karachi->qty_on_hand);
    }

    public function test_a_fifo_transfer_carries_each_source_layers_cost_and_age(): void
    {
        $item = $this->item('demo-xfer-lacquer');
        $source = $this->layers($item, 'demo-main', 'DEMO_TRANSFER_RECEIPT');
        $destination = $this->layers($item, 'demo-khi', 'DEMO_TRANSFER');

        // 15 L spans both source layers: all of the older 10 @90, then 5 of the newer 10 @110.
        $this->assertSame([['10.0000', '0.0000', '90.0000'], ['10.0000', '5.0000', '110.0000']], array_map(fn ($l): array => [$l->qty_received, $l->qty_remaining, $l->unit_cost], $source));
        // One destination layer per source-cost component, each keeping its source layer's receipt date.
        $this->assertSame([['10.0000', '90.0000'], ['5.0000', '110.0000']], array_map(fn ($l): array => [$l->qty_received, $l->unit_cost], $destination));
        $this->assertSame([$source[0]->received_at, $source[1]->received_at], [$destination[0]->received_at, $destination[1]->received_at]);
        // A FIFO item has no AVCO pools, so the transfer detail carries no pool identities.
        $detail = $this->detail($item);
        $this->assertNull($detail->source_avco_pool_id);
        $this->assertNull($detail->destination_avco_pool_id);
    }

    public function test_a_serial_transfer_moves_the_identified_unit_at_its_own_cost(): void
    {
        $item = $this->item('demo-xfer-laser');
        $move = DB::table('stock_moves')->where('item_id', $item->id)->where('source_type', 'DEMO_TRANSFER')->sole();
        $this->assertSame('SN-LM-002', $move->serial_no);

        $layers = DB::table('stock_layers as l')->join('locations as loc', 'loc.id', '=', 'l.location_id')->join('warehouses as w', 'w.id', '=', 'loc.warehouse_id')
            ->where('l.item_id', $item->id)->where('l.qty_remaining', '>', 0)->orderBy('l.serial_no')
            ->get(['l.serial_no', 'l.base_unit_cost', 'w.code as warehouse'])
            ->map(fn (object $l): array => [$l->serial_no, $l->warehouse, $l->base_unit_cost])->all();

        // Two units stay at Main; the moved one is at Karachi carrying exactly its own cost, not an average.
        $this->assertSame([['SN-LM-001', 'demo-main', '400.0000'], ['SN-LM-002', 'demo-khi', '450.0000'], ['SN-LM-003', 'demo-main', '500.0000']], $layers);
        $this->assertSame(['2.0000', '1.0000'], [$this->onHand($item, 'demo-main'), $this->onHand($item, 'demo-khi')]);
    }

    public function test_the_transfers_are_real_between_location_moves(): void
    {
        $moves = DB::table('stock_moves')->where('source_type', 'DEMO_TRANSFER')->orderBy('source_id')->get();

        $this->assertCount(4, $moves);
        foreach ($moves as $move) {
            $this->assertNotNull($move->from_location_id);
            $this->assertNotNull($move->to_location_id);
            $this->assertNotSame($move->from_location_id, $move->to_location_id);
        }
    }

    public function test_seeding_leaves_the_stock_other_scenarios_rely_on_untouched(): void
    {
        // A dedicated item per scenario: nothing else seeded, or driven by the e2e specs, may move.
        $touched = DB::table('stock_moves as m')->join('items as i', 'i.id', '=', 'm.item_id')
            ->where('m.source_type', 'like', 'DEMO_TRANSFER%')->pluck('i.code')->unique()->sort()->values()->all();

        $this->assertSame(['demo-xfer-emulsion', 'demo-xfer-lacquer', 'demo-xfer-laser', 'demo-xfer-varnish'], $touched);
    }

    public function test_a_rerun_after_live_drift_changes_nothing(): void
    {
        $item = $this->item('demo-xfer-emulsion');
        // Live stock drifts: an issue from the destination, which a recompute would trip over.
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => DB::table('locations')->where('warehouse_id', DB::table('warehouses')->where('code', 'demo-khi')->value('id'))->value('id'),
            'qty' => '5.0000', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'MANUAL_ISSUE', 'source_id' => 1,
        ]);
        $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), ['stock_moves', 'stock_layers', 'stock_transfer_details', 'avco_pools', 'items']);
        $before = $counts();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, $counts());
    }

    private function item(string $code): Item
    {
        return Item::query()->where('code', $code)->firstOrFail();
    }

    private function detail(Item $item): object
    {
        return DB::table('stock_transfer_details as d')->join('stock_moves as m', 'm.id', '=', 'd.stock_move_id')->where('m.item_id', $item->id)->sole(['d.*']);
    }

    private function onHand(Item $item, string $warehouseCode): string
    {
        $locations = DB::table('locations')->where('warehouse_id', DB::table('warehouses')->where('code', $warehouseCode)->value('id'))->select('id');

        return number_format((float) DB::table('stock_layers')->where('item_id', $item->id)->whereIn('location_id', $locations)->sum('qty_remaining'), 4, '.', '');
    }

    /** @return list<object> */
    private function layers(Item $item, string $warehouseCode, string $sourceType): array
    {
        $locations = DB::table('locations')->where('warehouse_id', DB::table('warehouses')->where('code', $warehouseCode)->value('id'))->select('id');

        return DB::table('stock_layers')->where('item_id', $item->id)->where('source_type', $sourceType)->whereIn('location_id', $locations)->orderBy('received_at')->orderBy('id')->get()->all();
    }
}
