<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Fx\EffectivePeriod;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use App\Modules\Sales\Support\Fx\FxPolicy;
use App\Modules\Sales\Support\Fx\ResolveQuotationFx;
use App\Support\CompanyContext;
use App\Support\RateType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** FR1 — dated FX resolution and frozen evidence over the real manual rate master, with fixtures built here and no seeded rates (ADR 0118). */
class QuotationFxTest extends TestCase
{
    use RefreshDatabase;

    private Currency $pkr;

    private Currency $usd;

    private Currency $eur;

    private Currency $jpy;

    private Currency $kwd;

    protected function setUp(): void
    {
        parent::setUp();
        $make = fn (string $code, int $places) => Currency::firstOrCreate(['code' => $code], ['name' => $code, 'symbol' => $code, 'decimal_places' => $places]);
        $this->pkr = $make('PKR', 2);
        $this->usd = $make('USD', 2);
        $this->eur = $make('EUR', 2);
        $this->jpy = $make('JPY', 0);
        $this->kwd = $make('KWD', 3);
        Company::findOrFail(1)->update(['base_currency_id' => $this->pkr->id]);
    }

    private function rate(Currency $from, string $rate, string $date, RateType $type = RateType::Spot, ?Currency $to = null, int $company = 1): ExchangeRate
    {
        $attributes = ['from_currency_id' => $from->id, 'to_currency_id' => ($to ?? $this->pkr)->id, 'rate' => $rate, 'rate_date' => $date, 'rate_type' => $type->value, 'source' => 'manual'];
        if ($company !== 1) { // another company's row cannot be made through the scoped model, so it is written as the master would hold it
            $id = DB::table('exchange_rates')->insertGetId($attributes + ['company_id' => $company, 'created_at' => now(), 'updated_at' => now()]);

            return ExchangeRate::withoutGlobalScopes()->findOrFail($id);
        }

        return ExchangeRate::create($attributes);
    }

    private function on(string $date): FinancialDate
    {
        return FinancialDate::explicit($date, 'Asia/Karachi', CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'));
    }

    private function fx(): ResolveQuotationFx
    {
        return app(ResolveQuotationFx::class);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function rule(Currency $currency, RoundingMode $mode = RoundingMode::HALF_UP): SettlementRule
    {
        return SettlementRule::forCurrency($currency, $mode);
    }

    public function test_the_same_currency_is_an_identity_that_reads_no_rate(): void
    {
        $this->assertSame(0, ExchangeRate::withoutGlobalScopes()->count());

        $evidence = ($this->fx())($this->usd->id, $this->usd->id, $this->on('2026-10-15'));

        $this->assertSame(FxBundleEvidence::ROUTE_IDENTITY, $evidence->route);
        $this->assertNull($evidence->sourceLeg);
        $this->assertNull($evidence->quoteLeg);
        $this->assertSame(['numerator' => '1', 'denominator' => '1'], $evidence->ratioFraction());
        $this->assertSame('123.4500', (string) $evidence->convert('123.45', $this->rule($this->usd))->settled);
    }

    public function test_the_accepted_bridge_example_converts_usd_100_to_eur_80_through_base_28000(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');
        $this->rate($this->eur, '350', '2026-10-15');

        $evidence = ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'));

        $this->assertSame(FxBundleEvidence::ROUTE_COMPANY_BASE_BRIDGE, $evidence->route);
        $this->assertSame(['numerator' => '4', 'denominator' => '5'], $evidence->ratioFraction());
        $this->assertSame('280.000000000', $evidence->sourceLeg->rate);
        $this->assertSame('350.000000000', $evidence->quoteLeg->rate);
        $this->assertSame([$this->usd->id, $this->pkr->id], [$evidence->sourceLeg->fromCurrencyId, $evidence->sourceLeg->toCurrencyId]);
        $this->assertSame('80.0000', (string) $evidence->convert('100', $this->rule($this->eur))->settled);
        // The base amount is the source leg alone: 100 × 280, never a figure rounded before the ratio is taken.
        $this->assertSame('28000.0000', (string) BigDecimal::of('100')->multipliedBy($evidence->sourceLeg->rate)->toScale(4));
    }

    public function test_a_currency_that_is_the_base_uses_an_explicit_identity_leg(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');

        $toBase = ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'));
        $this->assertTrue($toBase->quoteLeg->identity);
        $this->assertSame('1.000000000', $toBase->quoteLeg->rate);
        $this->assertSame('IDENTITY', $toBase->quoteLeg->source);
        $this->assertNull($toBase->quoteLeg->rateMasterId);
        $this->assertSame('28000.0000', (string) $toBase->convert('100', $this->rule($this->pkr))->settled);
        $this->assertFalse($toBase->sourceLeg->identity);

        // Base to a foreign currency needs only that currency's own base leg.
        $fromBase = ($this->fx())($this->pkr->id, $this->usd->id, $this->on('2026-10-15'));
        $this->assertTrue($fromBase->sourceLeg->identity);
        $this->assertSame('28.0000', (string) $fromBase->convert('7840', $this->rule($this->usd))->settled);
    }

    public function test_exact_date_is_the_default_and_a_prior_rate_is_not_used(): void
    {
        $this->rate($this->usd, '280', '2026-10-14');

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'))));
        $this->rate($this->usd, '281', '2026-10-15');
        $this->assertSame('281.000000000', ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'))->sourceLeg->rate);
    }

    public function test_a_missing_rate_is_refused_never_replaced_by_one_or_zero(): void
    {
        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'))));
        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(1, 30))));
    }

    public function test_bounded_prior_uses_the_latest_rate_within_the_maximum_age_inclusive(): void
    {
        $this->rate($this->usd, '270', '2026-10-01');
        $this->rate($this->usd, '280', '2026-10-12');
        $policy = FxPolicy::boundedPrior(1, 3);

        $leg = ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), $policy)->sourceLeg;
        $this->assertSame('280.000000000', $leg->rate, 'The latest on or before, not the oldest.');
        $this->assertSame('2026-10-12', $leg->actualDate);
        $this->assertSame('2026-10-15', $leg->requestedDate);
        $this->assertSame(3, $leg->ageDays, 'Exactly the maximum age is allowed.');

        $this->assertSame('FX_RATE_TOO_OLD', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-16'), $policy)), 'Four days old.');
        $this->assertSame('280.000000000', ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-16'), FxPolicy::boundedPrior(1, 4))->sourceLeg->rate);
        $this->assertSame('FX_RATE_TOO_OLD', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(1, 2))));
        // A zero bound is the exact-date rule: the rate must be dated that very day.
        $this->assertSame('FX_RATE_TOO_OLD', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-13'), FxPolicy::boundedPrior(1, 0))));
    }

    public function test_a_later_dated_rate_is_never_substituted(): void
    {
        $this->rate($this->usd, '290', '2026-10-16');
        $this->rate($this->usd, '295', '2026-10-20');

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(1, 30))));
        $this->rate($this->usd, '280', '2026-10-10');
        $this->assertSame('280.000000000', ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(1, 30))->sourceLeg->rate);
    }

    public function test_each_leg_is_selected_independently_and_both_ages_are_kept(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');
        $this->rate($this->eur, '350', '2026-10-13');

        $evidence = ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(2, 2));

        $this->assertSame([0, 2], [$evidence->sourceLeg->ageDays, $evidence->quoteLeg->ageDays]);
        $this->assertSame(['2026-10-15', '2026-10-13'], [$evidence->sourceLeg->actualDate, $evidence->quoteLeg->actualDate]);
        $this->assertSame('FX_RATE_TOO_OLD', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(2, 1))));
    }

    public function test_only_a_stored_spot_rate_in_the_source_to_base_direction_is_used(): void
    {
        // Average and closing rates for the same pair and date, and the reverse pair, are not SPOT source→base: none may be used.
        $this->rate($this->usd, '999', '2026-10-15', RateType::Average);
        $this->rate($this->usd, '998', '2026-10-15', RateType::Closing);
        $this->rate($this->pkr, '0.003571429', '2026-10-15', RateType::Spot, $this->usd);

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'))), 'No silent reciprocal and no other rate type.');

        $this->rate($this->usd, '280', '2026-10-15');
        $this->assertSame('280.000000000', ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'))->sourceLeg->rate, 'The SPOT row wins over same-day AVERAGE and CLOSING.');
        // A direct USD→EUR rate cannot replace the bridge.
        $this->rate($this->usd, '0.8', '2026-10-15', RateType::Spot, $this->eur);
        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'))), 'EUR→base is still missing.');
    }

    public function test_two_rates_cannot_compete_for_one_date(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');

        $this->expectException(QueryException::class);
        $this->rate($this->usd, '281', '2026-10-15');
    }

    public function test_a_non_positive_latest_rate_refuses_and_does_not_fall_back_to_an_older_one(): void
    {
        $this->rate($this->usd, '280', '2026-10-10');
        // The Currency owner's own positive-rate constraint (when present) is lifted inside this test's transaction, so the resolver's defence is what is
        // under test: a bad rate must refuse here even if one ever reaches the master.
        DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_rate_positive_check');
        $this->rate($this->usd, '0', '2026-10-14');

        $this->assertSame('FX_RATE_INVALID', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(1, 30))));
    }

    public function test_the_attempt_form_reports_unknown_with_every_failing_leg_and_never_partial(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');

        $outcome = ($this->fx())->attempt($this->usd->id, $this->eur->id, $this->on('2026-10-15'));
        $this->assertSame(Certainty::Unknown, $outcome->certainty);
        $this->assertNull($outcome->evidence);
        $this->assertSame([['FX_RATE_MISSING', 'quote_to_base']], array_map(fn (array $reason): array => [$reason['code'], $reason['leg']], $outcome->reasons));

        $none = ($this->fx())->attempt($this->jpy->id, $this->eur->id, $this->on('2026-10-15'));
        $this->assertSame(['source_to_base', 'quote_to_base'], array_column($none->reasons, 'leg'));

        $this->rate($this->eur, '350', '2026-10-15');
        $complete = ($this->fx())->attempt($this->usd->id, $this->eur->id, $this->on('2026-10-15'));
        $this->assertSame(Certainty::Complete, $complete->certainty);
        $this->assertSame([], $complete->reasons);
        $this->assertNotNull($complete->evidence);
    }

    public function test_unsupported_context_is_a_refusal_not_an_unknown(): void
    {
        $this->assertSame('FX_ROUTE_UNSUPPORTED', $this->refusal(fn () => ($this->fx())->attempt($this->usd->id, 999999, $this->on('2026-10-15'))));
        $this->assertSame('FX_POLICY_MISSING', $this->refusal(fn () => ($this->fx())->attempt($this->usd->id, $this->eur->id, $this->on('2026-10-15'),
            FxPolicy::boundedPrior(1, 3, new EffectivePeriod('2026-11-01')))));

        DB::table('companies')->where('id', 1)->update(['base_currency_id' => null]);
        $this->assertSame('FX_ROUTE_UNSUPPORTED', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'))));
        // Even the identity needs a base currency to be meaningful as company evidence.
        $this->assertSame('FX_ROUTE_UNSUPPORTED', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->usd->id, $this->on('2026-10-15'))));
    }

    public function test_conversion_settles_to_each_currencys_places_and_keeps_the_exact_value(): void
    {
        $this->rate($this->usd, '300', '2026-10-15');
        $this->rate($this->eur, '900', '2026-10-15'); // ratio 1/3, a repeating decimal
        $this->rate($this->jpy, '1.875', '2026-10-15');
        $this->rate($this->kwd, '910.5', '2026-10-15');
        $on = $this->on('2026-10-15');

        $third = ($this->fx())($this->usd->id, $this->eur->id, $on)->convert('10', $this->rule($this->eur));
        $this->assertSame('3.3300', (string) $third->settled);
        $this->assertNull($third->computedDecimal(), '10/3 has no exact decimal form; it stays a fraction.');
        $this->assertSame(['numerator' => '10', 'denominator' => '3'], $third->computedFraction());
        $this->assertSame('3.3400', (string) ($this->fx())($this->usd->id, $this->eur->id, $on)->convert('10', $this->rule($this->eur, RoundingMode::UP))->settled);

        // Zero-place yen: 100 USD × 300 / 1.875 = 16000 exactly; 1 USD = 160 exactly.
        $yen = ($this->fx())($this->usd->id, $this->jpy->id, $on);
        $this->assertSame('16000.0000', (string) $yen->convert('100', $this->rule($this->jpy))->settled);
        // A yen amount into dollars: 1 JPY = 0.00625 USD, settled to two places.
        $this->assertSame('0.0100', (string) ($this->fx())($this->jpy->id, $this->usd->id, $on)->convert('1', $this->rule($this->usd, RoundingMode::HALF_UP))->settled);
        $this->assertSame('0.0000', (string) ($this->fx())($this->jpy->id, $this->usd->id, $on)->convert('1', $this->rule($this->usd, RoundingMode::DOWN))->settled);
        // Three-place dinar: 1 USD = 300/910.5 KWD = 0.329489... → 0.329.
        $this->assertSame('0.3290', (string) ($this->fx())($this->usd->id, $this->kwd->id, $on)->convert('1', $this->rule($this->kwd, RoundingMode::HALF_UP))->settled);
        $this->assertSame('0.3300', (string) ($this->fx())($this->usd->id, $this->kwd->id, $on)->convert('1', $this->rule($this->kwd, RoundingMode::UP))->settled);

        $this->assertSame('CURRENCY_MISMATCH', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $on)->convert('10', $this->rule($this->usd))));
        $this->assertSame('NEGATIVE_AMOUNT', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $on)->convert('-1', $this->rule($this->eur))));
        $this->assertSame('AMOUNT_NOT_REPRESENTABLE', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $on)->convert('1.00005', $this->rule($this->eur))));
    }

    public function test_another_companys_rates_are_never_seen(): void
    {
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->rate($this->usd, '280', '2026-10-15', company: $other);

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->pkr->id, $this->on('2026-10-15'))));

        // And the resolver follows the active company: another company's own rate is found, this company's is not.
        $this->rate($this->usd, '111', '2026-10-15');
        DB::table('companies')->where('id', $other)->update(['base_currency_id' => $this->pkr->id]);
        $this->app->instance(CompanyContext::class, new class($other) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $evidence = app(ResolveQuotationFx::class)($this->usd->id, $this->pkr->id, $this->on('2026-10-15'));
        $this->assertSame('280.000000000', $evidence->sourceLeg->rate);
        $this->assertSame($other, $evidence->companyId);
    }

    public function test_evidence_survives_a_later_edit_or_delete_of_the_master_rate(): void
    {
        $usd = $this->rate($this->usd, '280', '2026-10-15');
        $eur = $this->rate($this->eur, '350', '2026-10-15');
        $evidence = ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'));
        $before = $evidence->toArray();
        $fingerprint = $evidence->fingerprint();

        $usd->update(['rate' => '999']);
        $eur->delete();

        $this->assertSame($before, $evidence->toArray());
        $this->assertSame($fingerprint, $evidence->fingerprint());
        $this->assertSame('80.0000', (string) $evidence->convert('100', $this->rule($this->eur))->settled);
        $this->assertSame($usd->id, $before['source_to_base_leg']['rate_master_id'], 'Provenance points at the master row; the value is a copy.');
        // A fresh resolution sees the changed master (and the deleted leg), which is exactly why a draft needs deliberate review.
        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'))));
    }

    public function test_a_replayed_resolution_is_identical_writes_nothing_and_the_fingerprint_tracks_content_only(): void
    {
        $this->rate($this->usd, '280', '2026-10-15');
        $this->rate($this->eur, '350', '2026-10-15');
        $tables = fn () => [DB::table('exchange_rates')->count(), DB::table('companies')->count(), DB::table('currencies')->count()];
        $counts = $tables();

        $first = ($this->fx())($this->usd->id, $this->eur->id, FinancialDate::explicit('2026-10-15', 'Asia/Karachi', CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC')));
        $second = ($this->fx())($this->usd->id, $this->eur->id, FinancialDate::explicit('2026-10-15', 'Asia/Karachi', CarbonImmutable::parse('2026-10-15 10:00:09', 'UTC')));

        $this->assertNotSame($first->toArray()['observed_at_utc'], $second->toArray()['observed_at_utc']);
        $this->assertSame($first->fingerprint(), $second->fingerprint(), 'The same conversion is one fingerprint however many times it is observed.');
        $this->assertSame($counts, $tables());

        $this->assertNotSame($first->fingerprint(), ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'), FxPolicy::boundedPrior(5, 2))->fingerprint(), 'A different policy is different evidence.');
        ExchangeRate::query()->where('from_currency_id', $this->usd->id)->update(['rate' => '281']);
        $this->assertNotSame($first->fingerprint(), ($this->fx())($this->usd->id, $this->eur->id, $this->on('2026-10-15'))->fingerprint());
    }

    public function test_the_evidence_is_a_decimal_string_document_with_the_policy_and_route(): void
    {
        $this->rate($this->usd, '280', '2026-10-14');
        $this->rate($this->eur, '350', '2026-10-15');
        $array = ($this->fx())($this->usd->id, $this->eur->id, FinancialDate::forInstant(CarbonImmutable::parse('2026-10-14 20:30:00', 'UTC'), 'Asia/Karachi'), FxPolicy::boundedPrior(7, 5))->toArray();

        $this->assertSame('FR1.1', $array['calculation_version']);
        $this->assertSame('2026-10-15', $array['requested_date'], 'The company-local date, not the UTC one.');
        $this->assertSame('Asia/Karachi', $array['time_zone']);
        $this->assertSame('COMPANY_BASE_BRIDGE', $array['route']);
        $this->assertSame(['version' => 7, 'source' => 'VERSIONED', 'mode' => 'BOUNDED_PRIOR', 'max_age_days' => 5, 'route' => 'COMPANY_BASE_BRIDGE', 'rate_type' => 'SPOT'], $array['policy']);
        $this->assertSame(['numerator' => '4', 'denominator' => '5'], $array['ratio']);
        $this->assertSame(1, $array['source_to_base_leg']['age_days']);
        $this->assertSame('manual', $array['source_to_base_leg']['source']);
        $this->assertIsString($array['quote_to_base_leg']['rate']);
        $this->assertSame($this->pkr->id, $array['base_currency_id']);
        $this->assertSame('BUILT_IN_DEFAULT', ($this->fx())($this->eur->id, $this->eur->id, $this->on('2026-10-15'))->toArray()['policy']['source']);
    }
}
