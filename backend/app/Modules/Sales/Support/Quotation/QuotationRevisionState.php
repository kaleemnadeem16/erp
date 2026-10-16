<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/**
 * The states a revision can be in today. ISSUED is deliberately absent: a state with no evidence behind it is not added early. It arrives with the issue
 * snapshot, the official number and their immutability guards (QL3), in the same migration.
 */
enum QuotationRevisionState: string
{
    /** Editable, possibly incomplete; governs nothing and is never sent. */
    case Draft = 'DRAFT';

    /** Terminal and immutable: a draft that was abandoned with a reason. It never held an official number. */
    case CancelledDraft = 'CANCELLED_DRAFT';
}
