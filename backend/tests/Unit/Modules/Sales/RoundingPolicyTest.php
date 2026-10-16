<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\Policy\DraftRevalidation;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyBinding;
use App\Modules\Sales\Support\Financial\Policy\SettlementEvidence;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Fx\EffectivePeriod;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FR4/PX6 — the pure contracts of the Sales rounding and unit-price precision policy, with figures that can be checked by hand (ADR 0120, 0144). */
class RoundingPolicyTest extends TestCase
{
    private function policy(int $scale = 4, SettlementRounding $rounding = SettlementRounding::HalfUp, int $company = 1, ?int $versionNo = 1): RoundingPolicy
    {
        return new RoundingPolicy($company, $versionNo === null ? null : 10 + $versionNo, $versionNo, $scale, $rounding, null);
    }

    private function rule(int $places, SettlementRounding $rounding = SettlementRounding::HalfUp): SettlementRule
    {
        return new SettlementRule(1, new CurrencyQuantum($places), $rounding->mode());
    }

    private function refused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail("Expected a refusal with {$code}.");
        } catch (DomainInvariantException $refusal) {
            $this->assertSame($code, $refusal->reasonCode);
        }
    }

    public function test_a_four_place_unit_price_is_preserved_exactly_under_the_default(): void
    {
        $policy = RoundingPolicy::builtInDefault(1);

        $this->assertSame('0.3350', (string) $policy->acceptUnitPrice('0.3350'));
        $this->assertSame('0.3350', (string) $policy->acceptUnitPrice('0.335'));
        $this->assertSame('120.0000', (string) $policy->acceptUnitPrice('120'));
        $this->assertSame('999999999999999.9999', (string) $policy->acceptUnitPrice('999999999999999.9999'));
        $this->assertTrue($policy->isBuiltIn());
        $this->assertSame(4, $policy->unitPriceScale);
        $this->assertSame(SettlementRounding::HalfUp, $policy->rounding);
    }

    #[DataProvider('entryPrecisions')]
    public function test_the_entry_precision_admits_exactly_its_places_and_never_rounds(int $scale, string $fits, string $stored, string $tooFine): void
    {
        $policy = $this->policy($scale);

        $this->assertSame($stored, (string) $policy->acceptUnitPrice($fits));
        $this->assertSame($stored, (string) $policy->acceptUnitPrice($stored), 'Trailing zeros are not extra places.');
        $this->refused(fn () => $policy->acceptUnitPrice($tooFine), 'UNIT_PRICE_PRECISION_EXCEEDED');
        $this->assertTrue($policy->conforms($fits));
        $this->assertFalse($policy->conforms($tooFine));
    }

    /** @return array<string, array{int, string, string, string}> */
    public static function entryPrecisions(): array
    {
        return ['two places' => [2, '0.34', '0.3400', '0.335'], 'three places' => [3, '0.335', '0.3350', '0.3351'], 'four places' => [4, '0.3351', '0.3351', '0.33511']];
    }

    public function test_unsound_prices_and_scales_are_refused(): void
    {
        $policy = $this->policy(4);
        $this->refused(fn () => $policy->acceptUnitPrice('-0.01'), 'NEGATIVE_AMOUNT');
        $this->refused(fn () => $policy->acceptUnitPrice('abc'), 'INVALID_AMOUNT');
        $this->refused(fn () => $policy->acceptUnitPrice('1e3'), 'INVALID_AMOUNT');
        $this->refused(fn () => $policy->acceptUnitPrice('1000000000000000'), 'AMOUNT_OVERFLOW');
        foreach ([0, 1, 5, -1] as $scale) {
            $this->refused(fn () => $this->policy($scale), 'ROUNDING_POLICY_INVALID');
        }
        $this->assertSame(2, $this->policy(2)->unitPriceScale);
    }

    public function test_the_accepted_settlement_example_three_by_point_three_three_five(): void
    {
        $rule = $this->rule(2);
        $line = Arithmetic::settleLine('0.3350', '3', $rule);

        $this->assertSame('1.0050', (string) $line->computedDecimal());
        $this->assertSame('1.0100', (string) $line->settled);
        $document = Arithmetic::sumSettled([$line, $line, $line]);
        $this->assertSame('3.0300', (string) $document, 'The document net is the sum of settled lines…');
        $rawHeader = $rule->settle(Arithmetic::lineValue('0.3350', '3')->plus(Arithmetic::lineValue('0.3350', '3'))->plus(Arithmetic::lineValue('0.3350', '3')));
        $this->assertSame('3.0200', (string) $rawHeader->settled, '…never a separately rounded raw total.');
    }

    public function test_each_supported_rounding_mode_settles_a_tie_as_it_promises(): void
    {
        // 1.0050 and 1.0150 at two places: the exact tie the modes disagree about.
        $expected = [
            'HALF_UP' => ['1.0100', '1.0200'],
            'HALF_EVEN' => ['1.0000', '1.0200'],
            'HALF_DOWN' => ['1.0000', '1.0100'],
            'UP' => ['1.0100', '1.0200'],
            'DOWN' => ['1.0000', '1.0100'],
        ];
        foreach (SettlementRounding::cases() as $rounding) {
            [$low, $high] = $expected[$rounding->value];
            $this->assertSame($low, (string) Arithmetic::settleLine('1.0050', '1', $this->rule(2, $rounding))->settled, $rounding->value.' 1.0050');
            $this->assertSame($high, (string) Arithmetic::settleLine('1.0150', '1', $this->rule(2, $rounding))->settled, $rounding->value.' 1.0150');
        }
        $this->assertCount(5, SettlementRounding::cases());
        $this->assertNull(SettlementRounding::tryFrom('CEILING'));
        $this->assertNull(SettlementRounding::tryFrom('UNNECESSARY'));
        $this->assertSame(RoundingMode::HALF_EVEN, SettlementRounding::HalfEven->mode());
    }

    public function test_settlement_follows_the_currencys_own_places_not_the_entry_precision(): void
    {
        // 3 × 0.3350 = 1.0050 exactly, settled in a zero-, two-, three- and four-place currency under the same HALF_UP policy.
        foreach ([0 => '1.0000', 2 => '1.0100', 3 => '1.0050', 4 => '1.0050'] as $places => $settled) {
            $this->assertSame($settled, (string) Arithmetic::settleLine('0.3350', '3', $this->rule($places))->settled, "{$places} places");
        }
        // Entry precision 2 and a three-place currency are independent: a 0.34 price settles to the dinar's mill.
        $this->assertSame('1.0200', (string) Arithmetic::settleLine($this->policy(2)->acceptUnitPrice('0.34'), '3', $this->rule(3))->settled);
    }

    public function test_the_residual_is_kept_as_signed_exact_evidence(): void
    {
        $up = SettlementEvidence::of(Arithmetic::settleLine('0.3350', '3', $this->rule(2)));
        $down = SettlementEvidence::of(Arithmetic::settleLine('0.3350', '3', $this->rule(2, SettlementRounding::Down)));

        $this->assertSame(['numerator' => '201', 'denominator' => '200'], $up['computed']);
        $this->assertSame('1.0100', $up['settled']);
        $this->assertSame(['numerator' => '1', 'denominator' => '200'], $up['residual'], 'Rounded up by one two-hundredth.');
        $this->assertSame(['numerator' => '-1', 'denominator' => '200'], $down['residual']);
        $this->assertSame([2, 'HALF_UP', 1], [$up['quantum_places'], $up['rounding_mode'], $up['currency_id']]);
        $exact = SettlementEvidence::of(Arithmetic::settleLine('0.5000', '2', $this->rule(2)));
        $this->assertSame(['numerator' => '0', 'denominator' => '1'], $exact['residual']);
    }

    public function test_the_fingerprint_identifies_the_rules_only(): void
    {
        $base = $this->policy(4, SettlementRounding::HalfUp, 1, 1);

        $this->assertSame($base->fingerprint(), $this->policy(4, SettlementRounding::HalfUp, 1, 7)->fingerprint(), 'A successor with identical rules is the same rules.');
        $this->assertSame($base->fingerprint(), RoundingPolicy::builtInDefault(1)->fingerprint(), 'The built-in default is these rules too.');
        $this->assertSame($base->fingerprint(), (new RoundingPolicy(1, 5, 2, 4, SettlementRounding::HalfUp, new EffectivePeriod('2026-01-01', '2026-06-01')))->fingerprint(), 'Dates do not change it, so closing a version cannot.');
        $this->assertNotSame($base->fingerprint(), $this->policy(2)->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->policy(4, SettlementRounding::HalfEven)->fingerprint());
        $this->assertNotSame($base->fingerprint(), $this->policy(4, SettlementRounding::HalfUp, 2)->fingerprint(), 'Another company is another policy.');
        $this->assertSame(64, strlen($base->fingerprint()));
    }

    public function test_a_draft_notices_a_changed_policy_and_reports_prices_that_no_longer_fit_without_rewriting_them(): void
    {
        $v1 = $this->policy(4, versionNo: 1);
        $bound = RoundingPolicyBinding::capture($v1, '2026-10-16');
        $prices = ['line-a' => '0.3350', 'line-b' => '1.20'];

        $unchanged = DraftRevalidation::review($bound, $v1, $prices);
        $this->assertFalse($unchanged->stale);
        $this->assertFalse($unchanged->requiresReview());
        $this->assertSame([], $unchanged->nonConforming);

        $sameRules = DraftRevalidation::review($bound, $this->policy(4, versionNo: 2), $prices);
        $this->assertFalse($sameRules->requiresReview(), 'A new version number with identical rules does not disturb a draft.');

        $v2 = $this->policy(2, versionNo: 2);
        $changed = DraftRevalidation::review($bound, $v2, $prices);
        $this->assertTrue($changed->requiresReview());
        $this->assertSame(['line-a'], $changed->nonConforming, 'Only the 0.3350 price no longer fits two places.');
        $this->assertSame([DraftRevalidation::POLICY_CHANGED, DraftRevalidation::UNIT_PRICE_NO_LONGER_FITS], $changed->reasons);
        $this->assertSame(['line-a' => '0.3350', 'line-b' => '1.20'], $prices, 'Nothing was rewritten.');

        $modeOnly = DraftRevalidation::review($bound, $this->policy(4, SettlementRounding::HalfEven, versionNo: 3), $prices);
        $this->assertTrue($modeOnly->requiresReview());
        $this->assertSame([], $modeOnly->nonConforming, 'A rounding change makes the draft stale without making any price wrong.');
        $this->assertSame(['fingerprint' => $v1->fingerprint(), 'version_no' => 1, 'as_of' => '2026-10-16'], $bound->toArray());
    }

    public function test_the_evidence_document_states_the_rules_and_their_source(): void
    {
        $default = RoundingPolicy::builtInDefault(3)->toArray();
        $this->assertSame('BUILT_IN_DEFAULT', $default['source']);
        $this->assertNull($default['version_no']);
        $this->assertSame([4, 'HALF_UP', 'LINE_NET_TO_CURRENCY_QUANTUM', 'SUM_OF_SETTLED_LINES', 'FR4.1', 3], [$default['unit_price_scale'], $default['rounding_mode'], $default['settlement_basis'], $default['document_net'], $default['calculation_version'], $default['company_id']]);

        $versioned = (new RoundingPolicy(1, 9, 4, 3, SettlementRounding::Down, new EffectivePeriod('2026-11-01', '2027-01-01')))->toArray();
        $this->assertSame(['VERSIONED', 4, 3, 'DOWN', '2026-11-01', '2027-01-01'], [$versioned['source'], $versioned['version_no'], $versioned['unit_price_scale'], $versioned['rounding_mode'], $versioned['effective_from'], $versioned['effective_end_exclusive']]);
        $policy = new RoundingPolicy(1, 9, 4, 3, SettlementRounding::Down, new EffectivePeriod('2026-11-01', '2027-01-01'));
        $this->assertFalse($policy->appliesOn('2026-10-31'));
        $this->assertTrue($policy->appliesOn('2026-11-01'));
        $this->assertTrue($policy->appliesOn('2026-12-31'));
        $this->assertFalse($policy->appliesOn('2027-01-01'));
    }
}
