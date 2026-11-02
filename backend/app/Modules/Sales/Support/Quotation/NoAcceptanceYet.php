<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\QuotationRevision;

/** No acceptance writer exists yet, so no acceptance can stand on any revision. B5 replaces this binding with the reader of the real acceptance records. */
final class NoAcceptanceYet implements QuotationAcceptanceState
{
    public function stands(QuotationRevision $revision): bool
    {
        return false;
    }
}
