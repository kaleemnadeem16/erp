<?php

namespace Tests\Unit\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Support\StockQuantity;
use Tests\TestCase;

/** PX2 — Inventory's door to the shared quantity contract: exact four-place stock quantities or a stable refusal, never a raw arithmetic exception. */
class StockQuantityTest extends TestCase
{
    private function code(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_sealed_packages_are_exact_or_refused(): void
    {
        $this->assertSame('7.5000', (string) StockQuantity::sealed('2.5', 3));
        $this->assertSame('0.0003', (string) StockQuantity::sealed('0.0001', 3));
        // A definition finer than the ledger used to make `toScale(4)` throw an unhandled arithmetic exception.
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => StockQuantity::sealed('0.33333', 3)));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => StockQuantity::packageContent('0.33333')));
        $this->assertSame('QUANTITY_OVERFLOW', $this->code(fn () => StockQuantity::sealed('9999999999', 200000)));
    }

    public function test_input_quantities_are_exact_four_place_values(): void
    {
        $this->assertSame('1.2750', (string) StockQuantity::positive('1.275'));
        $this->assertSame('0.0000', (string) StockQuantity::nonNegative('0'));
        $this->assertSame('-3.0000', (string) StockQuantity::signed('-3'));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => StockQuantity::positive('1.00005')));
        $this->assertSame('QUANTITY_ZERO', $this->code(fn () => StockQuantity::positive('0')));
        $this->assertSame('QUANTITY_NEGATIVE', $this->code(fn () => StockQuantity::nonNegative('-1')));
        $this->assertSame('QUANTITY_INVALID', $this->code(fn () => StockQuantity::positive('1,5')));
        $this->assertSame('QUANTITY_INVALID', $this->code(fn () => StockQuantity::positive(1.5)));
    }
}
