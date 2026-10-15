<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Support\Status;

/**
 * The internal pricing kernel (PR0): verify the Party and the source, choose one price by explicit rank, and settle the line under the caller's named
 * rule. Internal only — there is no route, no persistence and no authority here. A guarded Action must check its own duty and scope before calling it
 * and decide what the reader may see (`PricingResult::forReader`). It posts nothing, creates nothing and never derives a price from cost.
 */
final class ResolveSalesPrice
{
    public function __construct(private readonly PricingSourceRegistry $sources) {}

    /** @param  list<PriceCandidate>  $candidates  prices the caller has already found eligible for this Party and source */
    public function __invoke(PricingInput $input, array $candidates): PricingResult
    {
        // A prospect is a Party with no CustomerAccount; only a missing, foreign or archived Party refuses.
        $party = Party::query()->find($input->partyId);
        if ($party === null) {
            throw PricingRefusal::make(PricingRefusal::PARTY_NOT_FOUND, 'The Party was not found.', 'party_id');
        }
        if ($party->status !== Status::Active) {
            throw PricingRefusal::make(PricingRefusal::PARTY_NOT_FOUND, 'The Party is archived.', 'party_id');
        }

        $source = $this->sources->adapterFor($input->source->kind)->resolve($input);
        $price = PriceCandidateSelector::select($candidates, $input->settlement->currencyId);
        $line = Arithmetic::settleLine($price->unitAmount, $source->quantity, $input->settlement);

        return new PricingResult($source, $price, $source->quantity, $line, $source->cost);
    }
}
