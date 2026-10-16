<?php

declare(strict_types=1);

namespace App\Support\Quantity;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The shared quantity contract (ADR 0142): what a destination can hold exactly, and nothing more. It validates, returns the quantity at the destination
 * scale and refuses otherwise — it never rounds a physical quantity to make it fit. Pure and database-free, so Inventory, Purchasing and Sales use one rule.
 *
 *     $stock = QuantityContract::stockLedger();                    // decimal(19,4)
 *     $stock->represent('1.275');                                  // '1.2750'
 *     $stock->convert('7', '6.666666667');                         // refused: 46.666666669 has nine places
 *     QuantityContract::stockLedger('0.5')->represent('1.25');     // refused: not a multiple of 0.5
 *
 * Stored widths are decimal(19,scale): fifteen integer digits at four places, thirteen at six, ten at nine.
 */
final readonly class QuantityContract
{
    public const MAX_SCALE = 9;

    private const PRECISION = 19;

    public int $scale;

    public BigDecimal $maximum;

    public ?BigDecimal $increment;

    private function __construct(public QuantityContext $context, int $scale, string|BigDecimal|null $increment)
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw QuantityRefusal::make(QuantityRefusal::CONTRACT_INVALID, "A quantity scale of {$scale} is not supported (0 to ".self::MAX_SCALE.').');
        }
        $this->scale = $scale;
        $this->maximum = BigDecimal::of(10)->power(self::PRECISION - $scale)->minus(BigDecimal::ofUnscaledValue(1, $scale));
        $this->increment = $increment === null ? null : $this->acceptIncrement($increment);
    }

    public static function stockLedger(string|BigDecimal|null $increment = null): self
    {
        return new self(QuantityContext::StockLedger, 4, $increment);
    }

    /** A document-line quantity whose destination column holds `$scale` places. */
    public static function commercialLine(int $scale, string|BigDecimal|null $increment = null): self
    {
        return new self(QuantityContext::CommercialLine, $scale, $increment);
    }

    public static function assemblyTechnical(string|BigDecimal|null $increment = null): self
    {
        return new self(QuantityContext::AssemblyTechnical, 6, $increment);
    }

    public static function conversionEvidence(): self
    {
        return new self(QuantityContext::ConversionEvidence, 9, null);
    }

    /** The same destination with a permitted increment (every quantity must then be a whole multiple of it). */
    public function withIncrement(string|BigDecimal|null $increment): self
    {
        return new self($this->context, $this->scale, $increment);
    }

    /** True when the value would be accepted for a non-negative destination, without throwing. For detection and audits. */
    public function isRepresentable(mixed $value): bool
    {
        try {
            $this->represent($value, QuantitySign::NonNegative);

            return true;
        } catch (DomainInvariantException) {
            return false;
        }
    }

    /**
     * The quantity at exactly this destination's scale ("1.2750"), or a refusal: invalid, wrong sign, zero where positive is required, beyond the width,
     * more fractional digits than the destination holds, or not a multiple of the increment.
     */
    public function represent(mixed $value, QuantitySign $sign = QuantitySign::Positive, string $field = 'quantity'): BigDecimal
    {
        $number = self::parse($value, $field, QuantityRefusal::QUANTITY_INVALID);
        if ($number->isNegative() && $sign !== QuantitySign::Any) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_NEGATIVE, 'The quantity cannot be negative.', $field);
        }
        if ($number->isZero() && $sign === QuantitySign::Positive) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_ZERO, 'The quantity must be greater than zero.', $field);
        }
        if ($number->abs()->isGreaterThan($this->maximum)) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_OVERFLOW, 'The quantity is larger than the destination can hold.', $field);
        }
        if ($number->strippedOfTrailingZeros()->getScale() > $this->scale) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_NOT_REPRESENTABLE,
                "The quantity needs more than {$this->scale} decimal places and is not rounded.", $field);
        }
        $stored = $number->toScale($this->scale, RoundingMode::UNNECESSARY);
        if ($this->increment !== null && ! $stored->remainder($this->increment)->isZero()) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_INCREMENT_VIOLATED,
                "The quantity is not a multiple of the permitted increment {$this->increment}.", $field);
        }

        return $stored;
    }

    /**
     * Validate an increment *definition* for this destination: positive, an exact decimal, and no finer than the destination can ever reach, so it is
     * always a multiple of the smallest representable step. Returns it at the destination scale.
     */
    public function acceptIncrement(mixed $increment, string $field = 'quantity_precision'): BigDecimal
    {
        $value = self::parse($increment, $field, QuantityRefusal::INCREMENT_INVALID);
        if (! $value->isPositive()) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INVALID, 'An increment must be greater than zero.', $field);
        }
        if ($value->strippedOfTrailingZeros()->getScale() > $this->scale) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_NOT_REPRESENTABLE,
                "An increment finer than {$this->scale} decimal places can never be reached by this destination.", $field);
        }
        if ($value->isGreaterThan($this->maximum)) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INVALID, 'The increment is larger than the destination can hold.', $field);
        }

        return $value->toScale($this->scale, RoundingMode::UNNECESSARY);
    }

    /**
     * Validate an increment expressed in a *transaction unit* (a carton, a pack) against this destination: converted by the factor, its stock-unit
     * equivalent must be representable here and, if this destination has its own increment, a whole multiple of it. Returns the equivalent.
     */
    public function acceptTransactionIncrement(mixed $increment, mixed $factor, string $field = 'quantity_precision'): BigDecimal
    {
        $own = self::parse($increment, $field, QuantityRefusal::INCREMENT_INVALID);
        if (! $own->isPositive()) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INVALID, 'An increment must be greater than zero.', $field);
        }
        $evidence = self::factor($factor);
        $equivalent = $own->multipliedBy($evidence);
        if ($equivalent->strippedOfTrailingZeros()->getScale() > $this->scale) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_NOT_REPRESENTABLE,
                "The increment is finer than {$this->scale} decimal places once converted to the destination unit.", $field);
        }
        $equivalent = $equivalent->toScale($this->scale, RoundingMode::UNNECESSARY);
        if ($equivalent->isGreaterThan($this->maximum)) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INVALID, 'The converted increment is larger than the destination can hold.', $field);
        }
        if ($this->increment !== null && ! $equivalent->remainder($this->increment)->isZero()) {
            throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INCOMPATIBLE,
                'The converted increment is not a whole multiple of the destination increment.', $field);
        }

        return $equivalent;
    }

    /**
     * Convert a quantity in another unit into this destination by an evidence factor. The product must be exactly representable here (and on the
     * increment); a repeating or over-precise result is refused, never approximated: 7 × 6.666666667 = 46.666666669 does not fit four places.
     */
    public function convert(mixed $quantity, mixed $factor, QuantitySign $sign = QuantitySign::Positive, string $field = 'quantity'): ConvertedQuantity
    {
        $source = self::parse($quantity, $field, QuantityRefusal::QUANTITY_INVALID);
        if ($source->isNegative() && $sign !== QuantitySign::Any) {
            throw QuantityRefusal::make(QuantityRefusal::QUANTITY_NEGATIVE, 'The quantity cannot be negative.', $field);
        }
        $evidence = self::factor($factor);
        $exact = $source->multipliedBy($evidence);
        $result = $this->represent($exact, $sign, $field);

        return new ConvertedQuantity($source, $evidence, $exact, $result);
    }

    /** A conversion factor: positive, at most nine places and ten integer digits (decimal(19,9)); evidence, not a quantity. */
    public static function factor(mixed $factor, string $field = 'factor'): BigDecimal
    {
        $value = self::parse($factor, $field, QuantityRefusal::FACTOR_INVALID);
        if (! $value->isPositive()) {
            throw QuantityRefusal::make(QuantityRefusal::FACTOR_INVALID, 'A conversion factor must be greater than zero.', $field);
        }
        $evidence = self::conversionEvidence();
        if ($value->isGreaterThan($evidence->maximum) || $value->strippedOfTrailingZeros()->getScale() > $evidence->scale) {
            throw QuantityRefusal::make(QuantityRefusal::FACTOR_NOT_REPRESENTABLE, 'A conversion factor holds at most nine decimal places and ten integer digits.', $field);
        }

        return $value->toScale($evidence->scale, RoundingMode::UNNECESSARY);
    }

    /** Plain decimal strings, integers and BigNumbers only: no exponent form, no padding, no floats. */
    private static function parse(mixed $value, string $field, string $code): BigDecimal
    {
        // Decoded JSON gives floats, nulls and arrays: refuse them as invalid rather than let a binary float or a TypeError through.
        if (! is_string($value) && ! is_int($value) && ! $value instanceof BigNumber) {
            throw QuantityRefusal::make($code, 'The value is not an exact decimal number.', $field);
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw QuantityRefusal::make($code, 'The value is not an exact decimal number.', $field);
        }
        try {
            return $value instanceof BigNumber ? $value->toBigDecimal() : BigDecimal::of($value);
        } catch (MathException) {
            throw QuantityRefusal::make($code, 'The value is not an exact decimal number.', $field);
        }
    }
}
