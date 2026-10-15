<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

/**
 * Chooses the single winning candidate by explicit rank (R3, 0115). It never picks the cheapest, the newest or the first: no candidate refuses, a
 * candidate in another currency refuses, and two candidates sharing the best rank are a conflict to be resolved by configuration, not by ordering.
 */
final class PriceCandidateSelector
{
    /** @param  list<PriceCandidate>  $candidates */
    public static function select(array $candidates, int $currencyId): PriceCandidate
    {
        if ($candidates === []) {
            throw PricingRefusal::make(PricingRefusal::NO_ELIGIBLE_PRICE, 'There is no eligible price for this source.', 'candidates');
        }
        $ids = [];
        foreach ($candidates as $candidate) {
            if (isset($ids[$candidate->id])) {
                throw PricingRefusal::make(PricingRefusal::INVALID_INPUT, 'The same price candidate was supplied twice.', 'candidates');
            }
            $ids[$candidate->id] = true;
            if ($candidate->currencyId !== $currencyId) {
                throw PricingRefusal::make(PricingRefusal::PRICE_BASIS_INCOMPATIBLE, 'A candidate price is in a different currency from the quotation; conversion needs the FX bridge.', 'candidates');
            }
        }
        $best = min(array_map(static fn (PriceCandidate $candidate): int => $candidate->rank, $candidates));
        $winners = array_values(array_filter($candidates, static fn (PriceCandidate $candidate): bool => $candidate->rank === $best));
        if (count($winners) > 1) {
            throw PricingRefusal::make(PricingRefusal::PRICING_RULE_CONFLICT, 'Two eligible prices share the best rank; the conflict must be resolved by configuration.', 'candidates');
        }

        return $winners[0];
    }
}
