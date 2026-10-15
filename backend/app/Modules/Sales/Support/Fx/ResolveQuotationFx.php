<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\CompanyContext;
use App\Support\RateType;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Resolves the FX evidence for converting a source-currency amount into the quotation currency on one requested date (FR1, ADR 0118). It reads the
 * existing manual rate master (`ExchangeRate`, SPOT, this company) and nothing else, and it writes nothing.
 *
 *  - Same currency: IDENTITY, no rate read, nothing to be missing.
 *  - Otherwise the company-base bridge: source→base and quotation→base, each a stored SPOT row in that direction. A currency that *is* the base has an
 *    explicit identity leg. There is no silent reciprocal, no direct source→quote rate, no cheapest route and no provider fallback.
 *  - EXACT_DATE takes only a rate dated on the requested date. BOUNDED_PRIOR takes, per leg, the latest rate dated on or before it, no older than the
 *    policy's maximum age; a later-dated rate is never used. A missing, too-old or non-positive rate refuses — it is never replaced by one or zero.
 *
 * Two rates cannot compete for one date: the master is unique on (company, pair, date, type), so "the latest on or before" is a single row. Internal
 * only: it checks no permission, so a guarded Action must authorise its caller and scope before using it.
 */
final class ResolveQuotationFx
{
    public function __construct(private readonly CompanyContext $company) {}

    /** @throws DomainInvariantException with an FxRefusal code */
    public function __invoke(int $sourceCurrencyId, int $quoteCurrencyId, FinancialDate $date, ?FxPolicy $policy = null): FxBundleEvidence
    {
        [$evidence, $failures] = $this->resolve($sourceCurrencyId, $quoteCurrencyId, $date, $policy ?? FxPolicy::builtInExactDate());
        if ($evidence === null) {
            throw $failures[0]['refusal'];
        }

        return $evidence;
    }

    /**
     * The same resolution without the throw, for a caller that reports a figure as UNKNOWN instead of refusing the whole job. Only a missing, too-old
     * or invalid *rate* becomes UNKNOWN (each failing leg named); a bad currency, policy or date is still a refusal.
     */
    public function attempt(int $sourceCurrencyId, int $quoteCurrencyId, FinancialDate $date, ?FxPolicy $policy = null): FxOutcome
    {
        [$evidence, $failures] = $this->resolve($sourceCurrencyId, $quoteCurrencyId, $date, $policy ?? FxPolicy::builtInExactDate());
        if ($evidence !== null) {
            return FxOutcome::complete($evidence);
        }

        return FxOutcome::unknown(array_map(static fn (array $failure): array => ['code' => (string) $failure['refusal']->reasonCode, 'detail' => $failure['refusal']->getMessage(), 'leg' => $failure['leg']], $failures));
    }

    /** @return array{0: ?FxBundleEvidence, 1: list<array{leg: string, refusal: DomainInvariantException}>} */
    private function resolve(int $sourceId, int $quoteId, FinancialDate $date, FxPolicy $policy): array
    {
        if (! $policy->appliesOn($date->date)) {
            throw FxRefusal::make(FxRefusal::FX_POLICY_MISSING, 'The FX policy is not in force on the requested date.', 'policy');
        }
        $company = Company::query()->find($this->company->id());
        $base = $company?->base_currency_id;
        if ($base === null) {
            throw FxRefusal::make(FxRefusal::FX_ROUTE_UNSUPPORTED, 'The company has no base currency, so there is nothing to bridge through.', 'base_currency');
        }
        if (Currency::query()->whereIn('id', array_unique([$sourceId, $quoteId]))->count() !== count(array_unique([$sourceId, $quoteId]))) {
            throw FxRefusal::make(FxRefusal::FX_ROUTE_UNSUPPORTED, 'A currency in the conversion does not exist.', 'currency');
        }
        $companyId = $this->company->id();
        if ($sourceId === $quoteId) {
            return [new FxBundleEvidence($companyId, FxBundleEvidence::ROUTE_IDENTITY, $sourceId, $quoteId, (int) $base, $date, $policy, null, null), []];
        }

        $failures = [];
        $legs = [];
        foreach (['source_to_base' => $sourceId, 'quote_to_base' => $quoteId] as $name => $currencyId) {
            if ($currencyId === $base) {
                $legs[$name] = FxLegEvidence::identity($currencyId, $date->date);

                continue;
            }
            try {
                $legs[$name] = $this->leg($currencyId, (int) $base, $date, $policy);
            } catch (DomainInvariantException $refusal) {
                if (! in_array($refusal->reasonCode, FxRefusal::RATE_CODES, true)) {
                    throw $refusal;
                }
                $failures[] = ['leg' => $name, 'refusal' => $refusal];
            }
        }
        if ($failures !== []) {
            return [null, $failures];
        }

        return [new FxBundleEvidence($companyId, FxBundleEvidence::ROUTE_COMPANY_BASE_BRIDGE, $sourceId, $quoteId, (int) $base, $date, $policy, $legs['source_to_base'], $legs['quote_to_base']), []];
    }

    private function leg(int $fromCurrencyId, int $baseCurrencyId, FinancialDate $date, FxPolicy $policy): FxLegEvidence
    {
        $query = ExchangeRate::query()
            ->where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $baseCurrencyId)
            ->where('rate_type', RateType::Spot->value);

        $rate = $policy->mode === FxMode::ExactDate
            ? $query->where('rate_date', $date->date)->first()
            : $query->where('rate_date', '<=', $date->date)->orderByDesc('rate_date')->first();
        if ($rate === null) {
            throw FxRefusal::make(FxRefusal::FX_RATE_MISSING, $policy->mode === FxMode::ExactDate
                ? "No SPOT rate for currency {$fromCurrencyId} to base is dated exactly {$date->date}."
                : "No SPOT rate for currency {$fromCurrencyId} to base is dated on or before {$date->date}.", 'rate');
        }

        $actual = $rate->rate_date->format('Y-m-d');
        $age = $date->daysSince($actual);
        if ($age > $policy->maxAgeDays) {
            throw FxRefusal::make(FxRefusal::FX_RATE_TOO_OLD, "The latest SPOT rate for currency {$fromCurrencyId} is {$age} days old; the policy allows {$policy->maxAgeDays}.", 'rate');
        }
        try {
            $value = BigDecimal::of($rate->rate);
        } catch (MathException) {
            throw FxRefusal::make(FxRefusal::FX_RATE_INVALID, 'The stored rate is not a number.', 'rate');
        }
        if (! $value->isPositive()) {
            throw FxRefusal::make(FxRefusal::FX_RATE_INVALID, 'A rate must be greater than zero.', 'rate');
        }

        return new FxLegEvidence($fromCurrencyId, $baseCurrencyId, (string) $value->toScale(9), $date->date, $actual, $age, false, $rate->source, $rate->id);
    }
}
