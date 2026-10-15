<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use App\Models\Currency;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * One explicit settlement decision: which currency, which monetary quantum and which rounding mode turn an exact computed value into a settled amount.
 * There is no default mode and no "exact" mode — a caller that wants rounding has to name it, and a value that must not be rounded is refused elsewhere.
 */
final readonly class SettlementRule
{
    public function __construct(public int $currencyId, public CurrencyQuantum $quantum, public RoundingMode $mode)
    {
        if ($mode === RoundingMode::UNNECESSARY) {
            throw FinancialRefusal::make(FinancialRefusal::UNSUPPORTED_ROUNDING_MODE, 'A settlement rule must name a rounding mode; UNNECESSARY is a refusal, not a rule.', 'rounding');
        }
    }

    public static function forCurrency(Currency $currency, RoundingMode $mode): self
    {
        return new self($currency->id, CurrencyQuantum::forCurrency($currency), $mode);
    }

    public function settle(BigRational $computed): SettledAmount
    {
        return new SettledAmount($computed, StorageAmount::round($computed, $this->quantum->decimalPlaces, $this->mode, 'settled'), $this);
    }

    public function sameAs(self $other): bool
    {
        return $this->currencyId === $other->currencyId && $this->quantum->decimalPlaces === $other->quantum->decimalPlaces && $this->mode === $other->mode;
    }
}
