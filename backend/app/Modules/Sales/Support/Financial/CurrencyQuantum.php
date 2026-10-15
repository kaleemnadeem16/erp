<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use App\Models\Currency;
use Brick\Math\BigDecimal;

/**
 * A currency's smallest settled unit, from its configured decimal places. Zero to four places are supported — including the three-place currencies —
 * because storage holds four; anything outside refuses instead of being clamped (ADR 0120).
 */
final readonly class CurrencyQuantum
{
    public function __construct(public int $decimalPlaces)
    {
        if ($decimalPlaces < 0 || $decimalPlaces > StorageAmount::SCALE) {
            throw FinancialRefusal::make(FinancialRefusal::UNSUPPORTED_CURRENCY_PRECISION, "A currency with {$decimalPlaces} decimal places cannot be settled within decimal(19,4).", 'currency');
        }
    }

    public static function forCurrency(Currency $currency): self
    {
        return new self((int) $currency->decimal_places);
    }

    /** The quantum as a decimal, e.g. 0.01 for two places. */
    public function unit(): BigDecimal
    {
        // Exact by construction: 1 at the currency's scale (0.01 for two places). A division here would throw RoundingNecessaryException.
        return BigDecimal::ofUnscaledValue(1, $this->decimalPlaces);
    }
}
