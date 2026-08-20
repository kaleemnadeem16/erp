<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * Accounting cost basis — chosen per item, not one method for the whole
 * company. Independent of App\Modules\Inventory\Support\ItemTracking: BATCH
 * tracking does not imply FIFO, SERIAL tracking does not imply SPECIFIC —
 * see docs/domain/items.md "Valuation method" and
 * docs/decisions/0032-per-item-valuation-method.md.
 */
enum ValuationMethod: string
{
    case Avco = 'AVCO';
    case Fifo = 'FIFO';
    case Specific = 'SPECIFIC';
}
