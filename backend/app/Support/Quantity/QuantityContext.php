<?php

declare(strict_types=1);

namespace App\Support\Quantity;

/**
 * The kind of destination a quantity is going to (ADR 0142). A rule applies to the destination, not to a value because of where it was typed: the same
 * number may be a legitimate technical measurement and an unrepresentable stock quantity.
 */
enum QuantityContext: string
{
    /** Posted stock, receipts, counts, package evidence: decimal(19,4). */
    case StockLedger = 'STOCK_LEDGER';

    /** An offered, ordered or received quantity on a document line. Its scale is the destination column's and must be declared (four today wherever a column is four). */
    case CommercialLine = 'COMMERCIAL_LINE';

    /** Recipe and Assembly measurements and rates: decimal(19,6). */
    case AssemblyTechnical = 'ASSEMBLY_TECHNICAL';

    /** Unit conversion and packaging factors: decimal(19,9). Evidence, never the destination of a posted quantity. */
    case ConversionEvidence = 'CONVERSION_EVIDENCE';

    /** The fixed scale of the context, or null when the destination must declare it. */
    public function fixedScale(): ?int
    {
        return match ($this) {
            self::StockLedger => 4,
            self::CommercialLine => null,
            self::AssemblyTechnical => 6,
            self::ConversionEvidence => 9,
        };
    }
}
