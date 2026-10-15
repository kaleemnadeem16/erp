<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Financial\SettledAmount;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;

/**
 * The immutable conversion evidence for one source-currency → quotation-currency pair on one requested date: the policy, the base currency, both
 * captured legs and the exact ratio (source→base ÷ quote→base) kept as a fraction — never a rounded decimal. Everything is copied; nothing here reads the
 * rate master again. It is a value, not a row: the quotation candidate/issue owner (R5) will persist `toArray()` against its own company-composite key.
 *
 * Same-currency conversion is the IDENTITY route with no legs at all, so it can never be blocked by, or take, a rate.
 */
final readonly class FxBundleEvidence
{
    public const CALCULATION_VERSION = 'FR1.1';

    public const ROUTE_IDENTITY = 'IDENTITY';

    public const ROUTE_COMPANY_BASE_BRIDGE = 'COMPANY_BASE_BRIDGE';

    public function __construct(
        public int $companyId,
        public string $route,
        public int $sourceCurrencyId,
        public int $quoteCurrencyId,
        public int $baseCurrencyId,
        public FinancialDate $requested,
        public FxPolicy $policy,
        public ?FxLegEvidence $sourceLeg,
        public ?FxLegEvidence $quoteLeg,
    ) {
        $bridge = $route === self::ROUTE_COMPANY_BASE_BRIDGE;
        if ($route !== self::ROUTE_IDENTITY && ! $bridge) {
            throw FxRefusal::make(FxRefusal::FX_ROUTE_UNSUPPORTED, 'Only an identity or a company-base bridge route is supported.', 'route');
        }
        if ($route === self::ROUTE_IDENTITY && ($sourceCurrencyId !== $quoteCurrencyId || $sourceLeg !== null || $quoteLeg !== null)) {
            throw FxRefusal::make(FxRefusal::FX_ROUTE_UNSUPPORTED, 'An identity conversion needs the same currency and no legs.', 'route');
        }
        if ($bridge && ($sourceLeg === null || $quoteLeg === null || $sourceCurrencyId === $quoteCurrencyId)) {
            throw FxRefusal::make(FxRefusal::FX_ROUTE_UNSUPPORTED, 'A bridge needs two different currencies and both legs.', 'route');
        }
    }

    /** The exact multiplier from a source-currency amount to a quotation-currency amount. USD→base 280 over EUR→base 350 is 4/5. */
    public function ratio(): BigRational
    {
        if ($this->sourceLeg === null || $this->quoteLeg === null) {
            return BigRational::one();
        }

        return BigRational::of($this->sourceLeg->rate)->dividedBy($this->quoteLeg->rate)->simplified();
    }

    /** @return array{numerator: string, denominator: string} */
    public function ratioFraction(): array
    {
        $ratio = $this->ratio();

        return ['numerator' => (string) $ratio->getNumerator(), 'denominator' => (string) $ratio->getDenominator()];
    }

    /**
     * A source-currency amount converted to the quotation currency. The product is exact and nothing is rounded before the rule settles it; the rule must
     * be the quotation currency's, because converting into a currency the evidence does not describe would be a guess.
     */
    public function convert(string|BigDecimal $sourceAmount, SettlementRule $quoteRule): SettledAmount
    {
        if ($quoteRule->currencyId !== $this->quoteCurrencyId) {
            throw FinancialRefusal::make(FinancialRefusal::CURRENCY_MISMATCH, 'The settlement rule is not for the currency this evidence converts into.', 'currency');
        }

        return $quoteRule->settle(BigRational::of(StorageAmount::of($sourceAmount, 'source_amount'))->multipliedBy($this->ratio()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'company_id' => $this->companyId,
            'route' => $this->route,
            'requested_date' => $this->requested->date,
            'time_zone' => $this->requested->timeZone,
            'observed_at_utc' => $this->requested->observedAtUtc->format(CarbonImmutable::ATOM),
            'policy' => $this->policy->toArray(),
            'base_currency_id' => $this->baseCurrencyId,
            'source_currency_id' => $this->sourceCurrencyId,
            'quote_currency_id' => $this->quoteCurrencyId,
            'source_to_base_leg' => $this->sourceLeg?->toArray(),
            'quote_to_base_leg' => $this->quoteLeg?->toArray(),
            'ratio' => $this->ratioFraction(),
        ];
    }

    /** What the conversion *is*: stable for the same inputs and rates, ignoring only when it was observed. A replay of one request has one fingerprint. */
    public function fingerprint(): string
    {
        $content = $this->toArray();
        unset($content['observed_at_utc']);

        return OperationIntent::hash('fr1.fx_bundle', $content);
    }
}
