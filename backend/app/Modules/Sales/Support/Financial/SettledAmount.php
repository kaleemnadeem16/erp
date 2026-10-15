<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;

/**
 * A computed value and the amount it settled to, kept apart (ADR 0120). The computed value is exact (a rational); the settled amount is what the document
 * carries. Their difference is the settlement residual and is evidence, never silently absorbed.
 */
final readonly class SettledAmount
{
    public BigDecimal $settled;

    public function __construct(public BigRational $computed, BigDecimal $settled, public SettlementRule $rule)
    {
        $this->settled = StorageAmount::of($settled, 'settled');
    }

    /** The exact computed value as a decimal when it terminates (1.0050), otherwise null; use computedFraction() for the exact form. */
    public function computedDecimal(): ?BigDecimal
    {
        try {
            $decimal = $this->computed->toBigDecimal();

            return $decimal->getScale() < StorageAmount::SCALE ? $decimal->toScale(StorageAmount::SCALE) : $decimal;
        } catch (MathException) {
            return null;
        }
    }

    /** @return array{numerator: string, denominator: string} */
    public function computedFraction(): array
    {
        $lowest = $this->computed->simplified();

        return ['numerator' => (string) $lowest->getNumerator(), 'denominator' => (string) $lowest->getDenominator()];
    }

    /** Settled minus computed: positive when settlement rounded up, negative when it rounded down. */
    public function residual(): BigRational
    {
        return BigRational::of($this->settled)->minus($this->computed);
    }
}
