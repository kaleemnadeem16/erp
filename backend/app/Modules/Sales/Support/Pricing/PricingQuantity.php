<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Inventory\Models\Item;
use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Brick\Math\BigDecimal;

/**
 * The quantity rule of each pricing source (ADR 0142), applied by the adapter before any cost is read. There is deliberately no universal scale: the
 * same number can be a legitimate technical measurement and an unrepresentable stock quantity. A finer quantity is refused with the shared contract's
 * stable code, never rounded, and the result is the exact value at the source's own scale.
 *
 * - Direct and service Items: the stock ledger's four places, and a whole multiple of the Item's own increment when it has one.
 * - Assembly work: the six-place technical scale. This bounds the *technical* measure only; the scale an Assembly may be *offered* at on a quotation is
 *   still open (R2/R5, precision slices decision 8) and is not assumed to be six.
 * - Governed manual scope: four places, as every document-line column is four today.
 */
final class PricingQuantity
{
    public static function forItem(Item $item, mixed $quantity): BigDecimal
    {
        return QuantityContract::stockLedger($item->quantity_precision)->represent($quantity, QuantitySign::Positive);
    }

    public static function forAssemblyWork(mixed $quantity): BigDecimal
    {
        return QuantityContract::assemblyTechnical()->represent($quantity, QuantitySign::Positive);
    }

    public static function forManual(mixed $quantity): BigDecimal
    {
        return QuantityContract::commercialLine(4)->represent($quantity, QuantitySign::Positive);
    }
}
