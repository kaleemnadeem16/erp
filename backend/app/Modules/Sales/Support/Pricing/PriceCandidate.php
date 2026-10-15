<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;

/**
 * A price that an authorised caller has already found eligible for this Party and source: a unit amount in a currency, with a rank where a lower number
 * wins. No pricing master exists yet, so the kernel only chooses among the candidates it is handed; it never invents one. A zero unit amount is a
 * legitimate, explicit price — a missing price is the absence of a candidate.
 */
final readonly class PriceCandidate
{
    public BigDecimal $unitAmount;

    public function __construct(public string $id, public int $rank, string|BigDecimal $unitAmount, public int $currencyId)
    {
        if (trim($id) === '' || $rank < 1) {
            throw PricingRefusal::make(PricingRefusal::INVALID_INPUT, 'A price candidate needs an identity and a positive rank.', 'candidate');
        }
        $this->unitAmount = StorageAmount::of($unitAmount, 'unit_amount');
    }
}
