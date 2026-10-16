<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The decimal(19,4) storage contract for Sales money (invariant 6, ADR 0120): at most fifteen integer and four fractional digits, never negative,
 * carried as an exact decimal and never as a float. This is the scoped adapter that replaces nothing: the shared `MoneyCast` is untouched. It refuses,
 * rather than rounds, anything the column could not hold exactly — rounding is only ever an explicit settlement decision (see SettlementRule).
 */
final class StorageAmount
{
    public const SCALE = 4;

    public const MAX = '999999999999999.9999';

    /** Validate a decimal string or number as storage money. The result always has scale four ("1.0050"). */
    public static function of(string|int|BigNumber $value, string $field = 'amount'): BigDecimal
    {
        try {
            $number = $value instanceof BigNumber ? $value : BigDecimal::of($value);
            $decimal = $number->toBigDecimal();
        } catch (MathException) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_AMOUNT, 'The amount is not an exact decimal number.', $field);
        }

        return self::checked($decimal, $field);
    }

    /** A rational is storable only when it is exactly a four-place decimal; otherwise it must be settled first. */
    public static function ofRational(BigRational $value, string $field = 'amount'): BigDecimal
    {
        try {
            $decimal = $value->toBigDecimal();
        } catch (MathException) {
            throw FinancialRefusal::make(FinancialRefusal::AMOUNT_NOT_REPRESENTABLE, 'The amount has no exact decimal form and must be settled to a currency quantum first.', $field);
        }

        return self::checked($decimal, $field);
    }

    /** Settled value of a rational at an explicit scale and rounding mode, validated against the storage contract. */
    public static function round(BigRational $value, int $scale, RoundingMode $mode, string $field = 'amount'): BigDecimal
    {
        $rounded = BigDecimal::of($value->getNumerator())->dividedBy($value->getDenominator(), $scale, $mode);

        return self::checked($rounded, $field);
    }

    private static function checked(BigDecimal $decimal, string $field): BigDecimal
    {
        if ($decimal->isNegative()) {
            throw FinancialRefusal::make(FinancialRefusal::NEGATIVE_AMOUNT, 'The amount cannot be negative.', $field);
        }
        if ($decimal->strippedOfTrailingZeros()->getScale() > self::SCALE) {
            throw FinancialRefusal::make(FinancialRefusal::AMOUNT_NOT_REPRESENTABLE, 'The amount needs more than four decimal places and is not rounded silently.', $field);
        }
        $storage = $decimal->toScale(self::SCALE, RoundingMode::UNNECESSARY);
        if ($storage->isGreaterThan(self::MAX)) {
            throw FinancialRefusal::make(FinancialRefusal::AMOUNT_OVERFLOW, 'The amount exceeds decimal(19,4).', $field);
        }

        return $storage;
    }
}
