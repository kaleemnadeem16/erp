<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PricingResult;

/** The only provider delivered: it states, truthfully, that no authorised cost-blind adapter exists, so cost-consuming controls come out UNEVALUABLE. Not bound anywhere. */
final class UnavailableControlEvidence implements ControlEvidenceProvider
{
    public function costFor(SalesActorContext $actor, PricingResult $priced): FinancialResult
    {
        return FinancialResult::unknown([['code' => 'COST_ADAPTER_NOT_DELIVERED']]);
    }
}
