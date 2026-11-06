<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesPriceReference;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Illuminate\Support\Facades\DB;

/** @internal Reads only; callers holding the family lock may bind the independently approved result. */
final class GovernedReferences
{
    public static function economicKey(QuotationDraftLine $line): string
    {
        return OperationIntent::hash('sales.reference.economic.v1', [
            'kind' => $line->source_kind, 'item' => $line->item_id, 'measure' => $line->manual_measure, 'uom' => $line->priced_uom_id,
        ]);
    }

    public function resolve(QuotationRevision $revision, QuotationDraftLine $line): SalesPriceReference
    {
        $date = $revision->pricing_date?->format('Y-m-d');
        if ($date === null) {
            throw AuthorityRefusal::make('REFERENCE_MISSING', 'Choose a pricing date before resolving a governed reference.');
        }
        $anchor = DB::table('sales_quotation_reference_anchors')->where('company_id', $revision->company_id)->where('logical_scope_id', $line->logical_scope_id)->first();
        if ($anchor !== null && ! hash_equals($anchor->economic_key, self::economicKey($line))) {
            throw AuthorityRefusal::make('REFERENCE_CHANGED', 'The source or measure of a governed deliverable changed. Independent baseline review is required.');
        }
        $bound = DB::table('sales_quotation_reference_bindings')->where('company_id', $revision->company_id)->where('family_id', $revision->family_id)
            ->where('economic_key', self::economicKey($line))->value('reference_id');
        if ($bound !== null) {
            $reference = SalesPriceReference::query()->findOrFail($bound);
            if (! $this->compatible($reference, $revision, $line, $date)) {
                throw AuthorityRefusal::make('REFERENCE_CHANGED', 'The commercial case reference does not cover these terms. Independent reference review is required.');
            }

            return $reference;
        }
        $candidates = SalesPriceReference::query()->where('currency_id', $revision->currency_id)
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sales_price_reference_activations as a')->whereColumn('a.reference_id', 'sales_price_reference_versions.id')->whereColumn('a.company_id', 'sales_price_reference_versions.company_id'))
            ->get()->filter(fn (SalesPriceReference $r): bool => $this->compatible($r, $revision, $line, $date));
        // A new independently approved version of the SAME source code supersedes its old eligible version; different equal-rank sources remain ambiguous.
        $candidates = $candidates->groupBy('code')->map(fn ($versions) => $versions->sortByDesc('version_no')->first())->values();
        $rank = static fn (SalesPriceReference $r): array => [$r->source_kind === 'CUSTOMER_AGREEMENT' ? 2 : ($r->family_id === null ? 0 : 1), $r->priority];
        $candidates = $candidates->sort(fn (SalesPriceReference $a, SalesPriceReference $b): int => $rank($b) <=> $rank($a))->values();
        if ($candidates->isEmpty()) {
            throw AuthorityRefusal::make('REFERENCE_MISSING', 'No independently approved price reference covers this line. Exceptional review is required.');
        }
        if ($candidates->count() > 1 && $rank($candidates[0]) === $rank($candidates[1])) {
            throw AuthorityRefusal::make('REFERENCE_AMBIGUOUS', 'Two equally ranked price references apply. Ask the pricing administrator to resolve the conflict.');
        }

        return $candidates[0];
    }

    private function compatible(SalesPriceReference $r, QuotationRevision $revision, QuotationDraftLine $line, string $date): bool
    {
        return $r->company_id === $revision->company_id && $r->currency_id === $revision->currency_id
            && ($r->family_id === null || $r->family_id === $revision->family_id)
            && ($r->customer_id === null || $r->customer_id === $revision->recipient_party_id)
            && $r->item_id === $line->item_id && $r->manual_measure === $line->manual_measure && $r->uom_id === $line->priced_uom_id
            && $r->effective_from <= $date && ($r->effective_to === null || $r->effective_to > $date);
    }
}
