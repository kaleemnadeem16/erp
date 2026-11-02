<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Tax\Support\TaxClassification;

/**
 * The two source facts the Tax module needs and Sales must not invent: the jurisdiction a quotation is taxed in on a date, and a line's tax classification with its provenance.
 * Neither exists as persisted data yet (the Tax README: source-master classification and jurisdiction are not delivered), so the shipped binding says "unknown" and every line
 * stays blocked until an owner-reviewed source is bound. An implementation answers null rather than guess; it never defaults a jurisdiction or a classification.
 *
 * @internal Used by the FR3 adapter and by the tax-context authorizer, which must agree.
 */
interface QuotationTaxFacts
{
    /** The single jurisdiction this company's quotations are taxed in on `$taxDate` (Y-m-d), or null when it is not established. */
    public function jurisdiction(string $taxDate): ?string;

    /** The line's classification as the owning source declares it, or null when it does not. */
    public function classification(QuotationRevision $revision, QuotationDraftLine $line): ?TaxClassification;
}
