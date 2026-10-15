<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Exact Sales arithmetic (FR0). Unit amounts are storage money (at most four places) and a quantity is whatever its source already proved exact
 * (`PricingQuantity`, ADR 0142) — here it is only held to the widest quantity any destination can carry. The product is an exact rational, so nothing
 * is rounded until a SettlementRule says so. Settled amounts are summed as settled — a header is the sum of its settled lines, never a separately rounded raw total (ADR 0120).
 */
final class Arithmetic
{
    /** The arithmetic ceiling, not a business rule: the finest quantity any destination holds. Each source applies its own, narrower contract first. */
    public const QUANTITY_CEILING_SCALE = QuantityContract::MAX_SCALE;

    /** Exact line value: unit amount × quantity. */
    public static function lineValue(string|BigDecimal $unitAmount, string|BigDecimal $quantity): BigRational
    {
        $unit = StorageAmount::of($unitAmount, 'unit_amount');

        return BigRational::of($unit)->multipliedBy(self::quantity($quantity));
    }

    public static function settleLine(string|BigDecimal $unitAmount, string|BigDecimal $quantity, SettlementRule $rule): SettledAmount
    {
        return $rule->settle(self::lineValue($unitAmount, $quantity));
    }

    /** A non-negative quantity within the arithmetic ceiling, refused with the shared contract's stable codes and never truncated. */
    public static function quantity(string|BigDecimal $quantity): BigDecimal
    {
        return QuantityContract::commercialLine(self::QUANTITY_CEILING_SCALE)->represent($quantity, QuantitySign::NonNegative);
    }

    /**
     * Sum of settled amounts. All must share the same settlement rule; an empty list is refused because "no lines" is not a known zero.
     *
     * @param  list<SettledAmount>  $amounts
     */
    public static function sumSettled(array $amounts): BigDecimal
    {
        if ($amounts === []) {
            throw FinancialRefusal::make(FinancialRefusal::EMPTY_SUM, 'There is nothing to sum; an empty set is not a known zero.');
        }
        $rule = $amounts[0]->rule;
        $total = BigDecimal::zero();
        foreach ($amounts as $amount) {
            if ($amount->rule->currencyId !== $rule->currencyId) {
                throw FinancialRefusal::make(FinancialRefusal::CURRENCY_MISMATCH, 'Amounts in different currencies cannot be summed without an FX bridge.');
            }
            if (! $amount->rule->sameAs($rule)) {
                throw FinancialRefusal::make(FinancialRefusal::SETTLEMENT_MISMATCH, 'Amounts settled under different rules cannot be summed.');
            }
            $total = $total->plus($amount->settled);
        }

        return StorageAmount::of($total, 'total');
    }
}
