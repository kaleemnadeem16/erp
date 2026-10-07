<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\StockLayerAllocation;
use App\Modules\Inventory\Support\ValuationMethod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

/**
 * ADR 0090 §5: the movement boundary returns the allocation vector it actually used, in the same shape for every valuation method
 * (AVCO persists no per-layer consumption, so this is the only complete record), without changing what issue() and transfer() post.
 */
class PostedMovementAllocationTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    /** @return array<string, array{ValuationMethod}> */
    public static function methods(): array
    {
        return ['FIFO' => [ValuationMethod::Fifo], 'AVCO' => [ValuationMethod::Avco]];
    }

    /** @dataProvider methods */
    #[DataProvider('methods')]
    public function test_an_issue_returns_the_layers_it_drew_on_oldest_first(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '6', '8', '2026-01-01', 1);
        $this->receive($a, '14', '10', '2026-01-02', 2);
        [$older, $newer] = StockLayer::query()->orderBy('received_at')->pluck('id')->all();

        $posted = app(PostInventoryMovement::class)->issuePosted($this->issueInput($a, '9', 10));

        $this->assertSame([[$older, '6.0000'], [$newer, '3.0000']], array_map(fn (StockLayerAllocation $x) => [$x->sourceLayerId, $x->qty], $posted->allocations));
        $this->assertNull($posted->allocations[0]->destinationLayerId);
        $this->assertSame('0.0000', $posted->uncoveredQty);
        $this->assertFalse($posted->wasReplayed());
        $this->assertSame('9.0000', $posted->move->qty);
    }

    #[DataProvider('methods')]
    public function test_a_retry_reports_that_the_vector_was_not_captured_this_time(ValuationMethod $method): void
    {
        $this->fixture($method);
        $a = $this->location('a');
        $this->receive($a, '10', '8', '2026-01-01', 1);
        $first = app(PostInventoryMovement::class)->issuePosted($this->issueInput($a, '4', 2));

        $retry = app(PostInventoryMovement::class)->issuePosted($this->issueInput($a, '4', 2));

        $this->assertTrue($retry->wasReplayed());
        $this->assertNull($retry->allocations);
        $this->assertSame($first->move->id, $retry->move->id);
        $this->assertSame('6.0000', (string) StockLayer::query()->sole()->qty_remaining, 'a retry does not draw twice');
    }

    #[DataProvider('methods')]
    public function test_a_transfer_returns_each_source_layer_and_the_destination_layer_created_for_it(ValuationMethod $method): void
    {
        $this->fixture($method);
        [$from, $to] = [$this->location('from'), $this->location('to')];
        $this->receive($from, '6', '8', '2026-01-01', 1);
        $this->receive($from, '14', '10', '2026-01-02', 2);
        [$older, $newer] = StockLayer::query()->orderBy('received_at')->pluck('id')->all();

        $posted = app(PostInventoryMovement::class)->transferPosted([
            'item_id' => $this->item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'qty' => '9', 'uom_id' => $this->uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_TRANSFER', 'source_id' => 20,
        ]);

        $this->assertCount(2, $posted->allocations);
        $this->assertSame([$older, $newer], array_map(fn (StockLayerAllocation $x) => $x->sourceLayerId, $posted->allocations));
        foreach ($posted->allocations as $allocation) {
            $destination = StockLayer::query()->findOrFail($allocation->destinationLayerId);
            $this->assertSame($to->id, $destination->location_id);
            $this->assertSame($allocation->qty, (string) $destination->qty_received);
        }
        $this->assertSame('0.0000', $posted->uncoveredQty);
    }

    public function test_the_public_issue_and_transfer_still_return_the_move(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        [$from, $to] = [$this->location('from'), $this->location('to')];
        $this->receive($from, '10', '8', '2026-01-01', 1);

        $issued = app(PostInventoryMovement::class)->issue($this->issueInput($from, '1', 3));
        $moved = app(PostInventoryMovement::class)->transfer([
            'item_id' => $this->item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'qty' => '2', 'uom_id' => $this->uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_TRANSFER', 'source_id' => 4,
        ]);

        $this->assertSame('ISSUE', $issued->movement_kind->value ?? $issued->movement_kind);
        $this->assertSame('TRANSFER', $moved->movement_kind->value ?? $moved->movement_kind);
    }

    /** @return array<string, mixed> */
    private function issueInput(Location $location, string $qty, int $id): array
    {
        return [
            'item_id' => $this->item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'moved_at' => Carbon::parse('2026-01-09'), 'source_type' => 'TEST_ISSUE', 'source_id' => $id,
        ];
    }
}
