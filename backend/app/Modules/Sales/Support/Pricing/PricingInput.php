<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/**
 * Typed input to the internal resolver. The party is a Party id only — a prospect with no CustomerAccount is a first-class customer here. The server
 * resolves cost; nothing in this input is a caller-supplied cost, permission verdict or limit. The quantity is kept exactly as requested: its scale and
 * increment depend on the source, so the source adapter validates it (`PricingQuantity`) before any cost read and `ResolvedSource::$quantity` is the
 * proven value. A float or other non-decimal is refused there as QUANTITY_INVALID, never coerced.
 */
final readonly class PricingInput
{
    public function __construct(
        public SalesActorContext $actor,
        public int $partyId,
        public PricingSource $source,
        public mixed $quantity,
        public ?int $sellingUomId,
        public SettlementRule $settlement,
    ) {}
}
