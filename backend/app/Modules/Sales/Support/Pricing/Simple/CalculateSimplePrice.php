<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Simple;

use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\SettlementEvidence;
use App\Modules\Sales\Support\Financial\SettlementRule;
use Brick\Math\BigRational;

/** PR1 SIMPLE arithmetic only: exactly one declared benefit, then settle once. No rule selection, replacement confirmation, override or authority decision. */
final class CalculateSimplePrice
{
    /** @return array<string, mixed> internal evidence; null net is missing price, never zero */
    public function __invoke(SimplePriceInput $input, RoundingPolicy $policy, SettlementRule $rule): array
    {
        if ($input->companyId !== $policy->companyId || $input->currencyId !== $rule->currencyId || $rule->mode !== $policy->rounding->mode() || ! $policy->appliesOn($input->pricingDate)) {
            throw FinancialRefusal::make('FINANCIAL_CONTEXT_MISMATCH', 'The company, currency, date and settlement policy must agree.');
        }
        $evidence = [
            'line_key' => $input->lineKey, 'source_reference' => $input->sourceReference, 'company_id' => $input->companyId,
            'currency_id' => $input->currencyId, 'pricing_date' => $input->pricingDate, 'quantity' => (string) $input->quantity,
            'unit_price' => null, 'adjustment_kind' => $input->adjustment->value, 'adjustment_value' => (string) $input->adjustmentValue,
            'rounding_policy' => $policy->toArray(), 'rounding_fingerprint' => $policy->fingerprint(),
        ];
        if ($input->unitPrice === null) {
            return $evidence + ['certainty' => 'UNKNOWN', 'net' => null, 'reason' => 'PRICE_NOT_ENTERED'];
        }
        $unit = $policy->acceptUnitPrice($input->unitPrice);
        $base = Arithmetic::lineValue($unit, $input->quantity);
        $discount = match ($input->adjustment) {
            SimpleAdjustment::None => BigRational::zero(),
            SimpleAdjustment::Percent => $base->multipliedBy($input->adjustmentValue)->dividedBy(100),
            SimpleAdjustment::PerUnit => BigRational::of($input->adjustmentValue)->multipliedBy($input->quantity),
            SimpleAdjustment::PerLine => BigRational::of($input->adjustmentValue),
        };
        if ($discount->isGreaterThan($base)) {
            throw FinancialRefusal::make('SIMPLE_ADJUSTMENT_EXCEEDS_BASE', 'The adjustment cannot make a line negative.');
        }
        $settled = $rule->settle($base->minus($discount));
        $evidence['unit_price'] = (string) $unit;

        return $evidence + ['certainty' => 'COMPLETE', 'net' => (string) $settled->settled, 'base' => self::fraction($base), 'discount' => self::fraction($discount), 'settlement' => SettlementEvidence::of($settled)];
    }

    /** @return array{numerator: string, denominator: string} */
    private static function fraction(BigRational $value): array
    {
        $value = $value->simplified();

        return ['numerator' => (string) $value->getNumerator(), 'denominator' => (string) $value->getDenominator()];
    }
}
