<?php

namespace Tests\Support\Sales;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxFacts;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxNature;

/** A test double for the jurisdiction / classification source: one fixed jurisdiction and one explicit manual classification for every line. Never bound outside tests. */
final class FakeQuotationTaxFacts implements QuotationTaxFacts
{
    public function __construct(public ?string $jurisdiction = 'illustrative', public ?string $code = 'goods', public TaxNature $nature = TaxNature::Goods) {}

    public function jurisdiction(string $taxDate): ?string
    {
        return $this->jurisdiction;
    }

    public function classification(QuotationRevision $revision, QuotationDraftLine $line): ?TaxClassification
    {
        return $this->code === null ? null : new TaxClassification(SourceKind::Manual, $this->nature, $this->code, 'manual_explicit');
    }
}
