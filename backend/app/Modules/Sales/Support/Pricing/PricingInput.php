<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use Brick\Math\BigDecimal;

/**
 * Typed input to the internal resolver. The party is a Party id only — a prospect with no CustomerAccount is a first-class customer here. The server
 * resolves cost; nothing in this input is a caller-supplied cost, permission verdict or limit. Quantity is positive and finer-than-ledger input refuses.
 */
final readonly class PricingInput
{
    public BigDecimal $quantity;

    public function __construct(
        public SalesActorContext $actor,
        public int $partyId,
        public PricingSource $source,
        string|BigDecimal $quantity,
        public ?int $sellingUomId,
        public SettlementRule $settlement,
    ) {
        $this->quantity = Arithmetic::quantity($quantity);
        if ($this->quantity->isZero()) {
            throw PricingRefusal::make(PricingRefusal::INVALID_INPUT, 'The quantity to price must be greater than zero.', 'quantity');
        }
    }
}
