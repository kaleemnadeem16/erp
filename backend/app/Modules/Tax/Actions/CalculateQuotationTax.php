<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Tax\Support\Quotation\QuotationTaxCalculation;
use App\Modules\Tax\Support\Quotation\QuotationTaxDetermination;
use App\Modules\Tax\Support\Quotation\QuotationTaxSettlementPolicy;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use App\Modules\Tax\Support\TaxSupport;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/** Pure T2: input is canonical already-settled NET_FIXED/exclusive net and server-selected T1 evidence, not a rate selector. */
final class CalculateQuotationTax
{
    public function __invoke(QuotationTaxDetermination $determination, ?string $settledNet, string $intent): QuotationTaxCalculation
    {
        if ($determination->support === TaxSupport::Unsupported) {
            throw TaxRefusal::because('TAX_CALCULATION_UNSUPPORTED', 'The Tax context is unsupported.');
        }
        if ($intent !== 'NET_FIXED') {
            throw TaxRefusal::because('TAX_CALCULATION_UNSUPPORTED', 'Only exclusive NET_FIXED input is supported.');
        }
        $pins = $determination->internalEvidence();
        if (! is_int($pins['currency_places'] ?? null) || (int) ($pins['currency_id'] ?? 0) < 1) {
            throw TaxRefusal::because('TAX_CURRENCY_UNAVAILABLE', 'A known currency quantum is required.');
        }
        $quantum = new CurrencyQuantum($pins['currency_places']);
        $net = $settledNet === null ? null : StorageAmount::of($settledNet, 'net');
        if ($net !== null) {
            try {
                $net->toScale($quantum->decimalPlaces, RoundingMode::UNNECESSARY);
            } catch (MathException) {
                throw TaxRefusal::because('TAX_NET_NOT_SETTLED', 'Canonical net must already be settled to its currency quantum.');
            }
        }
        $tax = null;
        $computed = null;
        $residual = null;
        if ($determination->disposition !== TaxDisposition::Unknown) {
            $policy = new QuotationTaxSettlementPolicy($pins);
            $percentage = $pins['percentage'] ?? null;
            if ($percentage === null) {
                throw TaxRefusal::because('TAX_RATE_UNAVAILABLE', 'A pinned percentage is required, never assumed zero.');
            }
            try {
                $rate = BigDecimal::of((string) $percentage);
            } catch (MathException) {
                throw TaxRefusal::because('TAX_RATE_UNAVAILABLE', 'The pinned percentage is invalid.');
            }
            if ($rate->isLessThan(0) || $rate->isGreaterThan(100)) {
                throw TaxRefusal::because('TAX_RATE_UNAVAILABLE', 'The pinned percentage is outside the supported range.');
            }
            if ($determination->disposition !== TaxDisposition::Taxable || $rate->isZero()) {
                $computed = BigRational::zero();
            } elseif ($net !== null) {
                $computed = BigRational::of($net)->multipliedBy($rate)->dividedBy(100);
            }
            if ($computed !== null) {
                $settled = $policy->rule->settle($computed);
                $tax = $settled->settled;
                $residual = $settled->residual()->simplified();
            }
        }
        $gross = $net !== null && $tax !== null ? StorageAmount::of($net->plus($tax), 'gross') : null;
        $fact = static fn (?BigDecimal $amount): array => ['certainty' => $amount === null ? 'UNKNOWN' : 'COMPLETE', 'amount' => $amount === null ? null : (string) $amount];
        $safe = ['line_reference' => $determination->lineReference, 'disposition' => $determination->disposition->value, 'currency_id' => (int) $pins['currency_id'],
            'net' => $fact($net), 'tax' => $fact($tax), 'gross' => $fact($gross), 'payment' => ['certainty' => 'UNKNOWN', 'amount' => null],
            'evidence_reference' => $determination->fingerprint(), 'issue_ready' => false];
        $fraction = static fn (?BigRational $value): ?array => $value === null ? null : ['numerator' => (string) $value->simplified()->getNumerator(), 'denominator' => (string) $value->simplified()->getDenominator()];

        return new QuotationTaxCalculation($safe, $pins + ['line_reference' => $determination->lineReference, 'disposition' => $determination->disposition->value,
            'net' => $net === null ? null : (string) $net, 'raw_tax' => $fraction($computed), 'settled_tax' => $tax === null ? null : (string) $tax,
            'residual' => $fraction($residual), 'gross' => $gross === null ? null : (string) $gross, 'intent' => $intent]);
    }
}
