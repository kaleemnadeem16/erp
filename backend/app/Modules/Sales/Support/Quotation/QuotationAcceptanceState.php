<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\QuotationRevision;

/**
 * Whether any customer acceptance, claim or pending consent already stands on an issued revision. Withdrawal and replacement refuse when it does, and refuse rather than assume
 * zero when the answer cannot be given. Until acceptance exists (B5 rebinds this to the real reader) nothing can stand on a revision, which the default binding states plainly.
 *
 * @internal
 */
interface QuotationAcceptanceState
{
    public function stands(QuotationRevision $revision): bool;
}
