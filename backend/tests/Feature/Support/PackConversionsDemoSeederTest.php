<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PackConversionsDemoSeeder documents four scenarios and three resolution branches. A demo
 * seeder is real code and can be wrong in exactly the way its comment claims it is not, so each
 * scenario is proved here through the real conversion path a purchase order line takes —
 * not by reading the seeded rows back.
 */
final class PackConversionsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_the_same_pack_unit_holds_a_different_quantity_per_item(): void
    {
        $this->assertSame('25.000000000', (string) $this->factor('demo-pig-blue', 'bag'));
        $this->assertSame('20.000000000', (string) $this->factor('demo-pig-red', 'bag'));
    }

    public function test_pack_levels_on_one_item_are_each_stored_directly_against_its_stock_unit(): void
    {
        $this->assertSame('5.000000000', (string) $this->factor('demo-thinner', 'can'));
        $this->assertSame('200.000000000', (string) $this->factor('demo-thinner', 'drum'));
        // Not chained: the drum row is its own fact (200), not derived from the can row (5 × 40).
        $this->assertSame(2, ItemQuantityUnit::query()->where('item_id', $this->item('demo-thinner')->id)->count());
    }

    public function test_a_pack_conversion_crosses_dimension_where_the_global_service_cannot(): void
    {
        $tape = $this->item('demo-masking-tape');
        $roll = $this->uom('roll');

        // A roll is COUNT and the tape's stock unit is LENGTH: the global service refuses...
        $this->expectExceptionOfGlobalService(fn () => app(UomConversionService::class)->convert('1', $roll, $tape->stockUom));
        // ...the item's own pack conversion answers.
        $this->assertSame('50.000000000', (string) $this->factor('demo-masking-tape', 'roll'));
        $this->assertSame('1.000000000', (string) ItemQuantityUnit::query()->where('item_id', $tape->id)->sole()->quantity_precision);
    }

    public function test_a_same_dimension_pack_conversion_overrides_the_global_one_to_one(): void
    {
        $discs = $this->item('demo-sanding-disc');
        $box = $this->uom('box');

        // Box and each are both COUNT, so without a conversion of its own the global service
        // would quietly say 1 box = 1 disc.
        $this->assertSame('1', (string) app(UomConversionService::class)->convert('1', $box, $discs->stockUom)->stripTrailingZeros());
        $this->assertSame('50.000000000', (string) $this->factor('demo-sanding-disc', 'box'));
        $this->assertSame('1.000000000', $discs->quantity_precision);
    }

    public function test_without_a_pack_conversion_a_shared_dimension_falls_back_to_the_global_conversion(): void
    {
        // No row exists for thinner in millilitres; litre/millilitre is the same ratio for every item.
        $this->assertSame(0, ItemQuantityUnit::query()->where('item_id', $this->item('demo-thinner')->id)->where('uom_id', $this->uom('ml')->id)->count());
        $this->assertSame('0.001000000', (string) $this->factor('demo-thinner', 'ml'));
    }

    public function test_without_a_pack_conversion_or_a_shared_dimension_the_line_is_rejected_not_guessed(): void
    {
        // Drums are defined for the thinner only; blue pigment is stocked in kg.
        $this->expectExceptionOfGlobalService(fn () => $this->factor('demo-pig-blue', 'drum'));

        $this->expectException(DomainInvariantException::class);
        app(BuildPurchaseOrderLineAttributes::class)($this->order(), [
            'item_id' => $this->item('demo-pig-blue')->id, 'purchase_uom_id' => $this->uom('drum')->id,
            'ordered_qty' => '1', 'unit_price' => '10.0000',
        ]);
    }

    public function test_purchase_order_lines_convert_pack_quantities_to_stock_quantities(): void
    {
        $expected = [
            ['demo-pig-blue', 'bag', '10', '250.0000'],
            ['demo-pig-red', 'bag', '10', '200.0000'],
            ['demo-masking-tape', 'roll', '10', '500.0000'],
            ['demo-sanding-disc', 'box', '2', '100.0000'],
            ['demo-thinner', 'can', '3', '15.0000'],
            ['demo-thinner', 'drum', '1', '200.0000'],
        ];

        foreach ($expected as [$itemCode, $uomCode, $qty, $stockQty]) {
            $line = app(BuildPurchaseOrderLineAttributes::class)($this->order(), [
                'item_id' => $this->item($itemCode)->id, 'purchase_uom_id' => $this->uom($uomCode)->id,
                'ordered_qty' => $qty, 'unit_price' => '10.0000',
            ]);

            $this->assertSame($stockQty, $line['ordered_stock_qty'], "{$qty} {$uomCode} of {$itemCode}");
        }
    }

    public function test_the_pack_bought_items_default_to_their_pack_unit_at_their_supplier(): void
    {
        foreach ([['demo-masking-tape', 'roll'], ['demo-sanding-disc', 'box']] as [$itemCode, $uomCode]) {
            $relationship = ItemSupplier::query()->where('item_id', $this->item($itemCode)->id)->sole();

            $this->assertSame($this->uom($uomCode)->id, $relationship->purchase_uom_id, $itemCode);
        }
    }

    public function test_a_rerun_is_idempotent_and_reconciles_only_the_rows_it_owns(): void
    {
        $before = ItemQuantityUnit::query()->orderBy('id')->pluck('id')->all();
        $this->assertCount(6, $before);

        // Drift the seeded facts, add an unrelated local conversion and an unrelated item precision.
        ItemQuantityUnit::query()->where('item_id', $this->item('demo-pig-blue')->id)->update(['factor' => '99.000000000']);
        $local = ItemQuantityUnit::create(['item_id' => $this->item('demo-paint-white')->id, 'uom_id' => $this->uom('box')->id, 'factor' => '12.000000000']);
        Item::query()->where('code', 'demo-pig-red')->update(['quantity_precision' => '0.500000000']);

        $this->artisan('demo:seed')->assertSuccessful();

        // Same rows, drifted factor restored, nothing owned by someone else touched.
        $this->assertSame($before, ItemQuantityUnit::query()->whereKeyNot($local->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame('25.000000000', (string) $this->factor('demo-pig-blue', 'bag'));
        $this->assertSame('12.000000000', ItemQuantityUnit::query()->findOrFail($local->id)->factor);
        $this->assertSame('0.500000000', $this->item('demo-pig-red')->quantity_precision);
        $this->assertSame(1, DB::table('item_quantity_units')->where('item_id', $this->item('demo-masking-tape')->id)->count());
    }

    private function factor(string $itemCode, string $uomCode): BigDecimal
    {
        return app(ItemUomConversionService::class)->stockUnitFactor($this->item($itemCode), $this->uom($uomCode));
    }

    private function item(string $code): Item
    {
        return Item::query()->where('code', $code)->with('stockUom')->firstOrFail();
    }

    private function uom(string $code): Uom
    {
        return Uom::query()->where('code', $code)->firstOrFail();
    }

    private function order(): PurchaseOrder
    {
        return PurchaseOrder::query()->firstOrFail();
    }

    /** The global conversion service refuses a cross-dimension conversion with InvalidArgumentException. */
    private function expectExceptionOfGlobalService(callable $conversion): void
    {
        try {
            $conversion();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The global conversion service was expected to refuse a cross-dimension conversion.');
    }
}
