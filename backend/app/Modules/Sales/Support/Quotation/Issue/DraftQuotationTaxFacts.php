<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Tax\Support\Quotation\QuotationTaxSourceFacts;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxNature;
use Illuminate\Support\Facades\DB;

/**
 * The two facts Tax needs, from the only owners that may state them. The JURISDICTION and an ITEM line's classification are Tax-owned configuration facts (append-only, corrected
 * as new versions, written only under the staged configuration duty), read through Tax's own reader for the revision's tax-estimate date. A MANUAL line has no Item, so its
 * nature and classification are what the seller declared on the draft (the latest version; earlier ones stay as evidence). Nothing is defaulted or inferred: no fact, no date, or
 * a line kind with no source of classification answers null and the line stays blocked. Whether Tax actually covers a stated classification is Tax's decision, not this reader's.
 */
final class DraftQuotationTaxFacts implements QuotationTaxFacts
{
    public function __construct(private readonly QuotationTaxSourceFacts $source) {}

    public function jurisdiction(string $taxDate): ?string
    {
        return $this->source->jurisdiction($taxDate);
    }

    public function classification(QuotationRevision $revision, QuotationDraftLine $line): ?TaxClassification
    {
        $date = $revision->tax_estimate_date?->format('Y-m-d');
        if ($line->sourceKind() === PricingSourceKind::Manual) {
            $declared = DB::table('quotation_draft_tax_declarations')->where('revision_id', $revision->id)->where('logical_scope_id', $line->logical_scope_id)->orderByDesc('version_no')->first();
            $nature = $declared === null ? null : TaxNature::tryFrom((string) $declared->nature);

            return $declared === null || $nature === null ? null : new TaxClassification(SourceKind::Manual, $nature, (string) $declared->classification, 'manual_explicit');
        }
        if ($date === null || $line->item_id === null || ! in_array($line->sourceKind(), [PricingSourceKind::DirectItem, PricingSourceKind::ServiceItem], true)) {
            return null;
        }

        return $this->source->item((int) $line->item_id, $date);
    }
}
