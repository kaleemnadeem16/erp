<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Calculation;

use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\SettlementEvidence;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Pricing\Simple\CalculateSimplePrice;
use App\Modules\Tax\Support\Calculation\CalculateBasicTax;
use Brick\Math\BigRational;

/** B2/B3 arithmetic composed for a future B6 consumer. Pure, unexposed, no adoption/issue authority, profile lookup, cost read or state mutation. */
final class CalculateQuotationFinancials
{
    public function __construct(private readonly CalculateSimplePrice $prices, private readonly CalculateBasicTax $taxes) {}

    /** @param array<array-key, QuotationFinancialLine> $lines validated below as a non-empty list of distinct selected logical lines */
    public function __invoke(array $lines, RoundingPolicy $policy, SettlementRule $rule): QuotationFinancialCalculation
    {
        if (! array_is_list($lines) || $lines === [] || count($lines) > 1000) {
            throw FinancialRefusal::make('FINANCIAL_LINES_INVALID', 'A calculation needs one to 1000 selected logical lines.');
        }
        $seen = [];
        $date = $lines[0]->price->pricingDate;
        $rows = [];
        foreach ($lines as $line) {
            $input = $line->price;
            if (isset($seen[$input->lineKey]) || $input->pricingDate !== $date) {
                throw FinancialRefusal::make('FINANCIAL_LINES_INVALID', 'Line identities must be distinct and pricing dates must agree.');
            }
            $seen[$input->lineKey] = true;
            $price = ($this->prices)($input, $policy, $rule);
            $rawTax = $price['net'] === null ? null : ($this->taxes)($line->tax, BigRational::of($price['net']));
            $tax = $rawTax === null ? null : $rule->settle($rawTax);
            $gross = $tax === null ? null : StorageAmount::ofRational(BigRational::of($price['net'])->plus($tax->settled), 'gross');
            $rows[] = [
                'line_key' => $input->lineKey, 'price' => $price, 'net' => $price['net'], 'tax' => $tax === null ? null : (string) $tax->settled,
                'gross' => $gross === null ? null : (string) $gross, 'tax_settlement' => $tax === null ? null : SettlementEvidence::of($tax),
                'tax_facts' => ['disposition' => $line->tax->disposition->value, 'percentage' => $line->tax->percentage === null ? null : (string) $line->tax->percentage,
                    'component_reference' => $line->tax->componentReference, 'determination_reference' => $line->tax->determinationReference,
                    'method' => $line->tax->method, 'basis' => $line->tax->basis, 'included' => $line->tax->included],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['line_key'], $b['line_key']));

        return new QuotationFinancialCalculation($policy, $rule, $rows);
    }
}
