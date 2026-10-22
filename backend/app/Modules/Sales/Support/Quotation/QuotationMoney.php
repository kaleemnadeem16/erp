<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\Company;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\ResolveQuotationFx;
use App\Support\CompanyContext;

/**
 * Entered line money with its dual-money evidence (ADR 0012, R4): the seller's unit price in the quotation currency under the rounding policy in force on the
 * pricing date, the FX evidence for converting it into the company base currency on that date, and the base-currency unit price that evidence produces.
 * Either the whole set exists or the write is refused — a missing or unusable rate is a refusal of the money write, never a base amount of zero, one or a
 * guess. Nothing is read from "today": the pricing date is explicit. The evidence is copied onto the line, so editing the rate master later changes nothing.
 */
final class QuotationMoney
{
    public function __construct(private readonly CompanyContext $company, private readonly ResolveRoundingPolicy $policies, private readonly ResolveQuotationFx $fx) {}

    public function policyOn(string $pricingDate): RoundingPolicy
    {
        return ($this->policies)($pricingDate);
    }

    /**
     * @return array<string, mixed> the line's money columns
     */
    public function enter(int $quoteCurrencyId, ?string $pricingDate, mixed $unitPrice, ?RoundingPolicy $policy = null): array
    {
        if ($pricingDate === null) {
            throw QuotationRefusal::make(QuotationRefusal::PRICING_DATE_REQUIRED, 'A pricing date is required before a price can be entered: it fixes the rounding policy and the FX evidence.', 'pricing_date');
        }
        if (! is_string($unitPrice) && ! is_int($unitPrice)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A unit price is an exact decimal written as text.', 'unit_price');
        }
        $policy ??= $this->policyOn($pricingDate);
        $price = $policy->acceptUnitPrice((string) $unitPrice);

        $company = Company::query()->findOrFail($this->company->id());
        $baseCurrencyId = (int) $company->base_currency_id;
        $evidence = ($this->fx)($quoteCurrencyId, $baseCurrencyId, FinancialDate::explicit($pricingDate, $company->timezone, now()));
        // The base amount keeps storage precision (four places): it is evidence of the entered price, not a settled line.
        $base = $evidence->convert($price, new SettlementRule($baseCurrencyId, new CurrencyQuantum(StorageAmount::SCALE), $policy->rounding->mode()));

        return [
            'unit_price' => (string) $price, 'price_currency_id' => $quoteCurrencyId, 'base_currency_id' => $baseCurrencyId, 'base_unit_price' => (string) $base->settled,
            'fx_evidence' => $evidence->toArray(), 'fx_fingerprint' => $evidence->fingerprint(),
        ];
    }

    /** @return array<string, null> */
    public static function none(): array
    {
        return ['unit_price' => null, 'price_currency_id' => null, 'base_currency_id' => null, 'base_unit_price' => null, 'fx_evidence' => null, 'fx_fingerprint' => null];
    }
}
