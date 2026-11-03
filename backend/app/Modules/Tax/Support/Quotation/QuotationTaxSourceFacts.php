<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Quotation;

use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxNature;
use App\Support\CompanyContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Internal facts reader, not a permission boundary. The consumer must first prove the actor,
 * quotation family, Party and source line, and hold the Tax fence when using facts to issue.
 */
final class QuotationTaxSourceFacts
{
    public function jurisdiction(string $date): ?string
    {
        new EffectivePeriod($date);
        $rows = $this->onDate('tax_quotation_jurisdictions', $date)->limit(2)->get(['id', 'jurisdiction']);
        if ($rows->count() !== 1) {
            return null;
        }
        // The latest correction of the fact supersedes it for its own period; the original and earlier versions stay as evidence.
        $correction = $this->latestCorrection('jurisdiction_fact_id', (int) $rows->first()->id);

        return (string) ($correction->jurisdiction ?? $rows->first()->jurisdiction);
    }

    public function item(int $itemId, string $date): ?TaxClassification
    {
        new EffectivePeriod($date);
        if ($itemId < 1) {
            return null;
        }
        $rows = $this->onDate('tax_quotation_item_classifications', $date)->where('item_id', $itemId)->limit(2)->get(['id', 'nature', 'classification']);
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $this->latestCorrection('classification_fact_id', (int) $rows->first()->id) ?? $rows->first();
        $nature = TaxNature::tryFrom((string) $row->nature);
        if ($nature === null || $nature === TaxNature::Both) {
            return null;
        }

        return new TaxClassification(SourceKind::Item, $nature, (string) $row->classification, 'item_explicit');
    }

    private function latestCorrection(string $column, int $factId): ?object
    {
        return DB::table('tax_quotation_source_corrections')->where('company_id', app(CompanyContext::class)->id())->where($column, $factId)->orderByDesc('version_no')->first();
    }

    private function onDate(string $table, string $date): Builder
    {
        return DB::table($table)->where('company_id', app(CompanyContext::class)->id())->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date));
    }
}
