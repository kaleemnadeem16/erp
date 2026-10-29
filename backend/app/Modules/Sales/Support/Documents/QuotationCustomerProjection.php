<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;

/**
 * The customer-safe snapshot of an ISSUED quotation, built only from the frozen issue and its frozen lines (never from the draft, the preview or any cost, margin, control or
 * tax-evidence fact), through the allowlist of `QuotationCustomerDocumentAdapter`. The same issue always yields the same projection.
 */
final class QuotationCustomerProjection
{
    public static function ownerBindingIdentity(int $revisionId): string
    {
        return "quotation-revision:{$revisionId}";
    }

    public static function stableGenerationIdentity(int $issueId): string
    {
        return "issue:{$issueId}";
    }

    public static function forIssue(QuotationIssue $issue, QuotationRevision $revision): DocumentProjection
    {
        $company = Company::query()->findOrFail($issue->company_id);
        $lines = QuotationIssuedLine::query()->where('issue_id', $issue->id)->orderBy('sort_order')->orderBy('id')->get();
        $uoms = Uom::query()->whereIn('id', $lines->pluck('offered_uom_id')->filter()->all())->pluck('code', 'id');
        $currency = Currency::query()->findOrFail($issue->currency_id);
        $header = [
            'quotation_number' => $issue->official_number,
            'revision_ordinal' => $revision->revision_ordinal,
            'issued_on' => $issue->issued_at->setTimezone($company->timezone)->format('Y-m-d'),
            'currency_code' => $currency->code,
            'recipient_name' => (string) $revision->recipient()->firstOrFail()->name,
            'net_total' => (string) $issue->net_total,
        ];
        $header['valid_through'] = $issue->valid_through_local_date->format('Y-m-d');
        if ($issue->tax_total !== null) {
            $header['tax_total'] = (string) $issue->tax_total;
            $header['total_including_tax'] = (string) $issue->gross_total;
        }

        return new DocumentProjection(QuotationCustomerDocumentAdapter::KEY, 1, [
            'header' => $header,
            'sections' => ['lines' => $lines->map(fn (QuotationIssuedLine $line): array => array_filter([
                'section_title' => $line->section_title, 'description' => $line->description, 'membership' => $line->membership, 'quantity' => (string) $line->offered_quantity,
                'uom' => $line->offered_uom_id === null ? ($line->manual_measure) : ($uoms[$line->offered_uom_id] ?? null), 'unit_price' => (string) $line->unit_price, 'amount' => (string) $line->net,
            ], fn ($v): bool => $v !== null))->values()->all()],
        ]);
    }
}
