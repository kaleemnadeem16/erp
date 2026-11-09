<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Models\Currency;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Quotation\QuotationAdjustmentEngine;
use App\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;

/** @internal Exact pre-tax evidence, including all selectable lines. Caller proves access and holds the authority/revision fences. */
final class MeasureQuotationConcessions
{
    /** @return array<string, mixed> */
    public function measure(QuotationRevision $revision): array
    {
        $date = $revision->pricing_date?->format('Y-m-d') ?? throw AuthorityRefusal::make('REFERENCE_MISSING', 'A pricing date is required for authority evaluation.');
        $policy = app(ResolveRoundingPolicy::class)->__invoke($date);
        $rule = $policy->settlementRule(Currency::findOrFail($revision->currency_id));
        $base = app(CurrentCompany::class)->baseCurrencyId()
            ?? throw AuthorityRefusal::make('AUTHORITY_BASE_CURRENCY_REQUIRED', 'The company base currency must be configured before measuring authority.');
        $rows = [];
        $nets = [];
        $actors = [];
        $override = false;
        $undeclared = false;
        $engine = app(QuotationAdjustmentEngine::class);
        foreach ($revision->lines()->orderBy('id')->get() as $line) {
            if ($line->unit_price === null) {
                throw AuthorityRefusal::make('AUTHORITY_PRICE_MISSING', 'Every offered line needs a price before authority can be evaluated.');
            }
            if (app(DiscountConfiguration::class)->mode() === 'ENFORCE' && ! DB::table('sales_quotation_reference_anchors')->where('company_id', $revision->company_id)->where('logical_scope_id', $line->logical_scope_id)->exists()) {
                throw AuthorityRefusal::make('REFERENCE_NOT_BOUND', 'Capture the independently approved family reference before authority can clear this line.');
            }
            $reference = app(GovernedReferences::class)->resolve($revision, $line);
            $ratio = app(PinnedAuthorityFx::class)->ratio($revision, $line, $base);
            $quantity = $line->priced_quantity ?? $line->offered_quantity;
            $figures = $engine->line($line, $revision->currency_id, $date, $policy, $rule, $quantity);
            $counted = $line->membership === 'BASE' || ($line->membership === 'CHOOSE_ONE' && $line->is_baseline);
            if ($counted) {
                $nets[$line->id] = $figures['net'];
            }
            $referenceValue = Arithmetic::settleLine($reference->unit_price, $quantity, $rule)->settled;
            if ($referenceValue->isZero()) {
                throw AuthorityRefusal::make('REFERENCE_UNMEASURABLE', 'The settled reference is zero, so a percentage cannot be verified. Independent review is required.');
            }
            $override = $override || $line->net_override !== null;
            // An entered price below the governed unit price is not a declared automatic benefit.
            $undeclared = $undeclared || BigDecimal::of($line->unit_price)->isLessThan($reference->unit_price);
            if ($line->discount_kind !== null) {
                $actors[] = (int) $line->getAttribute('discount_entered_by');
            }
            $rows[$line->id] = [
                'line_id' => $line->id, 'economic_key' => GovernedReferences::economicKey($line), 'reference_id' => $reference->id,
                'logical_scope_id' => $line->logical_scope_id, 'source_kind' => $line->source_kind, 'item_id' => $line->item_id, 'priced_uom_id' => $line->priced_uom_id,
                'discount_kind' => $line->discount_kind, 'discount_value' => $line->discount_value, 'discount_entered_by' => $line->getAttribute('discount_entered_by'),
                'net_override' => $line->net_override, 'override_reason' => $line->override_reason, 'override_entered_by' => $line->getAttribute('override_entered_by'),
                'reference_fingerprint' => $reference->fingerprint, 'fx_fingerprint' => $line->fx_fingerprint, 'fx_evidence' => $line->fx_evidence,
                'quantity' => $quantity, 'reference_unit_price' => $reference->unit_price, 'reference_amount' => (string) $referenceValue,
                'entered_list_amount' => (string) $figures['list'], 'before_document_net' => (string) $figures['net'],
                'specific_amount' => (string) $figures['reduction'], 'specific_percent' => $line->discount_kind === 'PERCENT' ? (string) $line->discount_value : null,
                'ratio' => (string) $ratio, 'counts_in_total' => $counted,
            ];
        }
        if ($rows === []) {
            throw AuthorityRefusal::make('AUTHORITY_PRICE_MISSING', 'At least one priced line is required.');
        }
        $document = DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->first();
        $shares = [];
        if ($document !== null) {
            $shares = $engine->document($nets, $document->kind, $document->value, $rule)['shares'];
            $actors[] = (int) $document->entered_by;
        }
        $groups = [];
        $totalReference = BigDecimal::zero();
        $totalReduction = BigDecimal::zero();
        $baseReduction = BigRational::zero();
        $totalNet = BigDecimal::zero();
        $referenceBase = BigRational::zero();
        $netBase = BigRational::zero();
        foreach ($rows as $id => &$row) {
            $share = $shares[$id] ?? BigDecimal::zero();
            $net = BigDecimal::of($row['before_document_net'])->minus($share);
            $reduction = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($row['reference_amount'])->minus($net));
            $specific = BigDecimal::of($row['specific_amount'])->plus($share);
            $row['document_allocation'] = (string) StorageAmount::of($share);
            $row['final_net'] = (string) StorageAmount::of($net);
            $row['reduction_amount'] = (string) $reduction;
            $row['reduction_base_exact'] = (string) BigRational::of($reduction)->multipliedBy($row['ratio'])->simplified();
            $row['specific_base_exact'] = (string) BigRational::of($specific)->multipliedBy($row['ratio'])->simplified();
            $row['reference_base_exact'] = (string) BigRational::of($row['reference_amount'])->multipliedBy($row['ratio'])->simplified();
            $row['final_net_base_exact'] = (string) BigRational::of($net)->multipliedBy($row['ratio'])->simplified();
            $row['reference_base_amount'] = (string) StorageAmount::round(BigRational::of($row['reference_base_exact']), 4, $policy->rounding->mode());
            $row['final_net_base_amount'] = (string) StorageAmount::round(BigRational::of($row['final_net_base_exact']), 4, $policy->rounding->mode());
            $row['reduction_base_amount'] = (string) StorageAmount::round(BigRational::of($row['reduction_base_exact']), 4, $policy->rounding->mode());
            $key = $row['economic_key'];
            $group = $groups[$key] ?? ['reference' => BigDecimal::zero(), 'reduction' => BigDecimal::zero(), 'base' => BigRational::zero(), 'specific_base' => BigRational::zero(), 'specific_percent' => BigRational::zero()];
            $group['reference'] = $group['reference']->plus($row['reference_amount']);
            $group['reduction'] = $group['reduction']->plus($reduction);
            $group['base'] = $group['base']->plus($row['reduction_base_exact']);
            $group['specific_base'] = $group['specific_base']->plus($row['specific_base_exact']);
            $specificPercent = $row['specific_percent'] === null ? BigRational::of($row['specific_amount'])->multipliedBy(100)->dividedBy($row['reference_amount']) : BigRational::of($row['specific_percent']);
            if ($document !== null && $document->kind === 'PERCENT' && $row['counts_in_total']) {
                $specificPercent = BigRational::max($specificPercent, BigRational::of($document->value));
            }
            $group['specific_percent'] = BigRational::max($group['specific_percent'], $specificPercent);
            $groups[$key] = $group;
            $totalReference = $totalReference->plus($row['reference_amount']);
            $totalReduction = $totalReduction->plus($reduction);
            $baseReduction = $baseReduction->plus($row['reduction_base_exact']);
            $totalNet = $totalNet->plus($net);
            $referenceBase = $referenceBase->plus($row['reference_base_exact']);
            $netBase = $netBase->plus($row['final_net_base_exact']);
        }
        unset($row);
        foreach ($groups as &$group) {
            $group['percent'] = $group['reference']->isZero() ? '0' : (string) BigRational::of($group['reduction'])->multipliedBy(100)->dividedBy($group['reference'])->simplified();
            $group = array_map(fn ($value): string => (string) $value, $group);
        }
        unset($group);
        $result = [
            'version' => 'QDA.1', 'currency_id' => $revision->currency_id, 'base_currency_id' => $base, 'rounding_fingerprint' => $policy->fingerprint(), 'currency_quantum_places' => $rule->quantum->decimalPlaces,
            'lines' => array_values($rows), 'groups' => $groups, 'reference_amount' => (string) StorageAmount::of($totalReference),
            'reduction_amount' => (string) StorageAmount::of($totalReduction), 'reduction_base_exact' => (string) $baseReduction->simplified(),
            'reduction_base_amount' => (string) StorageAmount::round($baseReduction, 4, $policy->rounding->mode()),
            'reference_base_exact' => (string) $referenceBase->simplified(), 'final_net_base_exact' => (string) $netBase->simplified(),
            'reference_base_amount' => (string) StorageAmount::round($referenceBase, 4, $policy->rounding->mode()),
            'final_net_amount' => (string) StorageAmount::of($totalNet), 'final_net_base_amount' => (string) StorageAmount::round($netBase, 4, $policy->rounding->mode()),
            'document_percent' => $totalReference->isZero() ? '0' : (string) BigRational::of($totalReduction)->multipliedBy(100)->dividedBy($totalReference)->simplified(),
            'actors' => array_values(array_unique($actors)), 'override' => $override, 'undeclared_reduction' => $undeclared,
            'document_discount' => $document === null ? null : ['kind' => $document->kind, 'value' => (string) StorageAmount::of($document->value), 'entered_by' => $document->entered_by,
                'allocation_fingerprint' => OperationIntent::hash('sales.authority.document_allocation.v1', ['method' => 'LARGEST_REMAINDER', 'kind' => $document->kind,
                    'value' => $document->value, 'nets' => array_map('strval', $nets), 'shares' => array_map('strval', $shares)])],
        ];
        $result['fingerprint'] = OperationIntent::hash('sales.concession_measure.v1', $result);

        return $result;
    }
}
