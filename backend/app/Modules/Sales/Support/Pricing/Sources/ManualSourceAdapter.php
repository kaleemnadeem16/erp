<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingQuantity;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;

/** Governed manual scope: the description and measure are the identity; no Item, Assembly or account is invented. Cost is what the caller declared, else UNKNOWN. */
final class ManualSourceAdapter implements PricingSourceAdapter
{
    public function kind(): PricingSourceKind
    {
        return PricingSourceKind::Manual;
    }

    public function resolve(PricingInput $input): ResolvedSource
    {
        $source = $input->source;
        $quantity = PricingQuantity::forManual($input->quantity);

        return new ResolvedSource($this->kind(), null, (string) $source->description, null, $quantity,
            $source->manualCost ?? FinancialResult::unknown([['code' => 'MANUAL_COST_NOT_PROVIDED']]), ['measure' => $source->measure, 'cost_source' => 'declared']);
    }
}
