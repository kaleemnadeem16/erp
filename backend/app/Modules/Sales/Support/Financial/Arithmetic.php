<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;

/**
 * Exact Sales arithmetic (FR0). Unit amounts are storage money (at most four places) and quantities have at most nine places (the ledger quantity
 * precision); the product is an exact rational, so nothing is rounded until a SettlementRule says so. Settled amounts are summed as settled — a header
 * is the sum of its settled lines, never a separately rounded raw total (ADR 0120).
 */
final class Arithmetic
{
    public const QUANTITY_SCALE = 9;

    public const QUANTITY_MAX = '999999999999.999999999';

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

    /** A validated non-negative quantity that the ledger precision can hold exactly; finer input is refused, never truncated. */
    public static function quantity(string|BigDecimal $quantity): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
        } catch (MathException) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_QUANTITY, 'The quantity is not an exact decimal number.', 'quantity');
        }
        if ($value->isNegative()) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_QUANTITY, 'The quantity cannot be negative.', 'quantity');
        }
        if ($value->stripTrailingZeros()->getScale() > self::QUANTITY_SCALE) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_QUANTITY, 'The quantity is finer than the ledger precision and is not truncated.', 'quantity');
        }
        if ($value->isGreaterThan(self::QUANTITY_MAX)) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_QUANTITY, 'The quantity exceeds the supported range.', 'quantity');
        }

        return $value;
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
