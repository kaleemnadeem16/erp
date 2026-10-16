<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\UomDimension;

/** PX3 (ADR 0142): a purchase quantity converts to stock units exactly or is refused — never stored as a rounded value. */
class PurchasingQuantityConversionTest extends PurchaseOrderFoundationTest
{
    private function code(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('ordered_qty', $refusal->field);

            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    /** @return array{0: PurchaseOrder, 1: Item, 2: Uom, 3: Uom} */
    private function cartonWorld(string $factor): array
    {
        [$order, $item, $uom] = $this->makeOrder();
        $carton = Uom::create(['code' => 'ctn', 'name' => 'Carton', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $carton->id, 'factor' => $factor]);

        return [$order, $item, $uom, $carton];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function line(string $qty, mixed $order, mixed $item, Uom $unit, array $extra = []): array
    {
        return app(BuildPurchaseOrderLineAttributes::class)($order, array_merge($this->lineAttributes($order, $item, $unit), ['purchase_uom_id' => $unit->id, 'ordered_qty' => $qty], $extra));
    }

    public function test_seven_cartons_of_a_repeating_factor_are_refused_not_stored_as_46_6667(): void
    {
        [$order, $item, , $carton] = $this->cartonWorld('6.666666667');

        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $this->line('7', $order, $item, $carton)));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $this->line('0.3', $order, $item, $carton)));
    }

    public function test_exact_conversions_keep_their_quantities(): void
    {
        [$order, $item, , $carton] = $this->cartonWorld('12.5');

        $this->assertSame('37.5000', $this->line('3', $order, $item, $carton)['ordered_stock_qty']);
        $this->assertSame('12.5000', $this->line('1', $order, $item, $carton)['ordered_stock_qty']);
        $this->assertSame('3.1250', $this->line('0.25', $order, $item, $carton)['ordered_stock_qty']);
        $this->assertSame('12.500000000', $this->line('1', $order, $item, $carton)['uom_factor']);
    }

    public function test_a_fraction_that_lands_exactly_on_the_ledger_is_valid_a_milli_unit_that_does_not_is_not(): void
    {
        [$order, $item, , $carton] = $this->cartonWorld('0.001');

        $this->assertSame('0.0010', $this->line('1', $order, $item, $carton)['ordered_stock_qty']);
        $this->assertSame('0.0005', $this->line('0.5', $order, $item, $carton)['ordered_stock_qty']);
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $this->line('0.05', $order, $item, $carton)));
    }

    public function test_the_item_stock_increment_is_checked_on_the_converted_quantity(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $item->update(['quantity_precision' => '0.5']);

        $this->assertSame('1.5000', $this->line('1.5', $order, $item, $uom)['ordered_stock_qty']);
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->code(fn () => $this->line('1.25', $order, $item, $uom)));
    }

    public function test_the_order_quantity_must_fit_its_own_four_place_column(): void
    {
        [$order, $item, $uom] = $this->makeOrder();

        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $this->line('5.00001', $order, $item, $uom)));
        $this->assertSame('QUANTITY_ZERO', $this->code(fn () => $this->line('0', $order, $item, $uom)));
        $this->assertSame('QUANTITY_NEGATIVE', $this->code(fn () => $this->line('-1', $order, $item, $uom)));
        $this->assertSame('5.0000', $this->line('5.0000', $order, $item, $uom)['ordered_stock_qty']);
    }

    public function test_a_unit_conversion_with_a_repeating_factor_is_still_defined_as_evidence(): void
    {
        [, $item] = $this->cartonWorld('6.666666667');

        $this->assertSame('6.666666667', ItemQuantityUnit::query()->where('item_id', $item->id)->sole()->factor);
    }
}
