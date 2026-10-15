<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;

/** Service Item. No service cost provider exists, so its cost is explicitly UNKNOWN — never zero, never forced through an Assembly. */
final class ServiceItemSourceAdapter implements PricingSourceAdapter
{
    public function kind(): PricingSourceKind
    {
        return PricingSourceKind::ServiceItem;
    }

    public function resolve(PricingInput $input): ResolvedSource
    {
        $item = ItemSourceLookup::sellable($input);
        if ($item->type !== ItemType::Service) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_INCOMPATIBLE, 'Only a service item can be declared as a service source.', 'source');
        }

        return new ResolvedSource($this->kind(), $item->id, $item->code.' — '.$item->name, $item->stock_uom_id,
            FinancialResult::unknown([['code' => 'SERVICE_COST_PROVIDER_UNSUPPORTED']]), ['cost_source' => 'none']);
    }
}
