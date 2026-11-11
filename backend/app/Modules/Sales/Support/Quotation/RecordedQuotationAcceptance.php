<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\QuotationRevision;

/** Acceptance facts never disappear; caller holds the shared Sales authority coordinator before lifecycle locks. */
final class RecordedQuotationAcceptance implements QuotationAcceptanceState
{
    public function stands(QuotationRevision $revision): bool
    {
        return CustomerAcceptance::query()->where('revision_id', $revision->id)->exists();
    }
}
