<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Financial\Allocation\AllocateFinancialResiduals;
use App\Modules\Sales\Support\Financial\Allocation\AllocationShare;
use App\Modules\Sales\Support\Financial\Allocation\FinancialAllocation;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\SettlementRule;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FR4 — deterministic largest-remainder allocation of a settled amount, with figures that can be checked by hand (ADR 0120). Pure: no database, so nothing can be persisted or posted. */
class AllocateFinancialResidualsTest extends TestCase
{
    private function rule(int $places = 2): SettlementRule
    {
        return new SettlementRule(1, new CurrencyQuantum($places), RoundingMode::HALF_UP);
    }

    /** @param  list<AllocationShare>  $shares */
    private function allocate(string $total, array $shares, int $places = 2): FinancialAllocation
    {
        return (new AllocateFinancialResiduals)($total, $shares, $this->rule($places));
    }

    /** @return list<AllocationShare> */
    private function equal(int $count, string $weight = '100'): array
    {
        return array_map(fn (string $key): AllocationShare => AllocationShare::eligible($key, $weight), array_slice(['a', 'b', 'c', 'd', 'e'], 0, $count));
    }

    /** @return array<string, string> key => allocated amount */
    private function amounts(FinancialAllocation $allocation): array
    {
        $out = [];
        foreach ($allocation->shares as $share) {
            $out[$share->key] = (string) $share->allocated;
        }

        return $out;
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

    public function test_one_unit_over_three_equal_components_is_a_third_each_with_the_extra_cent_to_the_first_key(): void
    {
        $allocation = $this->allocate('1.00', $this->equal(3));

        $this->assertSame(['a' => '0.3400', 'b' => '0.3300', 'c' => '0.3300'], $this->amounts($allocation));
        $this->assertSame('1.0000', (string) $allocation->sum());
        $this->assertTrue($allocation->for('a')->gotExtraQuantum);
        $this->assertFalse($allocation->for('b')->gotExtraQuantum);
        $this->assertSame('LARGEST_REMAINDER', FinancialAllocation::METHOD);
    }

    public function test_the_accepted_aggregate_tax_example_places_two_cents_on_the_first_two_of_three(): void
    {
        // Three 0.05 lines at 10% tax: lines round to 0.03 in total, the eligible aggregate to 0.02, allocated 0.01 / 0.01 / 0.
        $allocation = $this->allocate('0.02', $this->equal(3, '0.05'));

        $this->assertSame(['a' => '0.0100', 'b' => '0.0100', 'c' => '0.0000'], $this->amounts($allocation));
    }

    public function test_the_tie_is_broken_by_key_not_by_position_so_presentation_cannot_change_it(): void
    {
        $forward = $this->allocate('0.02', $this->equal(3, '0.05'));
        $reversed = $this->allocate('0.02', array_reverse($this->equal(3, '0.05')));
        $shuffled = $this->allocate('0.02', [$this->equal(3, '0.05')[1], $this->equal(3, '0.05')[2], $this->equal(3, '0.05')[0]]);

        $this->assertSame($this->amounts($forward), $this->amounts($reversed));
        $this->assertSame($forward->fingerprint(), $reversed->fingerprint());
        $this->assertSame($forward->fingerprint(), $shuffled->fingerprint());
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($share): string => $share->key, $reversed->shares), 'Canonical key order.');
        // Byte order, not numeric: "10" sorts before "9", so callers should pass opaque or zero-padded identities.
        $numeric = $this->allocate('0.01', [AllocationShare::eligible('9', '1'), AllocationShare::eligible('10', '1')]);
        $this->assertSame(['10' => '0.0100', '9' => '0.0000'], $this->amounts($numeric));
    }

    public function test_unequal_remainders_go_to_the_largest_fraction_first(): void
    {
        // 100 quanta over weights 1:2:4 (of 7): 14.2857, 28.5714, 57.1428 → 14 + 28 + 57 = 99, the last quantum to the largest fraction (.5714, b).
        $allocation = $this->allocate('1.00', [AllocationShare::eligible('a', '1'), AllocationShare::eligible('b', '2'), AllocationShare::eligible('c', '4')]);

        $this->assertSame(['a' => '0.1400', 'b' => '0.2900', 'c' => '0.5700'], $this->amounts($allocation));
        $this->assertTrue($allocation->for('b')->gotExtraQuantum);
    }

    public function test_one_benefit_across_unequal_bases_conserves_the_total(): void
    {
        $allocation = $this->allocate('10.00', [AllocationShare::eligible('a', '100'), AllocationShare::eligible('b', '250'), AllocationShare::eligible('c', '650')]);

        $this->assertSame(['a' => '1.0000', 'b' => '2.5000', 'c' => '6.5000'], $this->amounts($allocation));
    }

    public function test_a_negative_residual_is_the_mirror_of_a_positive_one(): void
    {
        $positive = $this->allocate('1.00', $this->equal(3));
        $negative = $this->allocate('-1.00', $this->equal(3));

        $this->assertSame(['a' => '-0.3400', 'b' => '-0.3300', 'c' => '-0.3300'], $this->amounts($negative));
        $this->assertSame('-1.0000', (string) $negative->sum());
        foreach ($positive->shares as $share) {
            $this->assertSame((string) $share->allocated->negated(), (string) $negative->for($share->key)->allocated);
        }
        $this->assertSame(['a' => '-0.0100', 'b' => '0.0000', 'c' => '0.0000'], $this->amounts($this->allocate('-0.01', $this->equal(3))), 'A single owed cent goes to one component only.');
    }

    public function test_a_zero_total_allocates_zero_everywhere_and_is_still_evidence(): void
    {
        $allocation = $this->allocate('0.00', $this->equal(3));
        $this->assertSame(['a' => '0.0000', 'b' => '0.0000', 'c' => '0.0000'], $this->amounts($allocation));
        $this->assertSame('0.0000', (string) $allocation->sum());

        $noWeight = $this->allocate('0', [AllocationShare::eligible('a', '0')]);
        $this->assertSame(['a' => '0.0000'], $this->amounts($noWeight));
        $none = $this->allocate('0', [AllocationShare::ineligible('a', 'EXEMPT')]);
        $this->assertSame('EXEMPT', $none->for('a')->reason);
    }

    #[DataProvider('currencyPlaces')]
    public function test_the_quantum_follows_the_currency(int $places, string $total, array $expected): void
    {
        $allocation = $this->allocate($total, $this->equal(3), $places);

        $this->assertSame($expected, $this->amounts($allocation));
        $this->assertSame($places, $allocation->rule->quantum->decimalPlaces);
        $this->assertTrue($allocation->sum()->isEqualTo($total));
    }

    /** @return array<string, array{int, string, array<string, string>}> */
    public static function currencyPlaces(): array
    {
        return [
            'zero places: 10 yen' => [0, '10', ['a' => '4.0000', 'b' => '3.0000', 'c' => '3.0000']],
            'two places: 1.00' => [2, '1.00', ['a' => '0.3400', 'b' => '0.3300', 'c' => '0.3300']],
            'three places: 1.000' => [3, '1.000', ['a' => '0.3340', 'b' => '0.3330', 'c' => '0.3330']],
            'four places: 0.0100 (the FX base example)' => [4, '0.0100', ['a' => '0.0034', 'b' => '0.0033', 'c' => '0.0033']],
        ];
    }

    public function test_computed_bases_settled_header_and_allocated_contributions_stay_distinct(): void
    {
        // ADR 0120: three 0.0100 lines at FX 0.333000000. Each computed base is 0.00333 → 0.0033, together 0.0099; the header converts to 0.0100.
        $rule = $this->rule(4);
        $line = Arithmetic::settleLine('0.0100', '0.333', $rule);
        $computedBases = Arithmetic::sumSettled([$line, $line, $line]);
        $header = $rule->settle(Arithmetic::lineValue('0.0100', '0.333')->multipliedBy(3));

        $this->assertSame('0.0099', (string) $computedBases->toScale(4));
        $this->assertSame('0.0100', (string) $header->settled);
        $allocation = $this->allocate((string) $header->settled, $this->equal(3), 4);
        $this->assertSame(['a' => '0.0034', 'b' => '0.0033', 'c' => '0.0033'], $this->amounts($allocation));
        $this->assertSame('0.0001', (string) $allocation->sum()->minus($computedBases)->toScale(4), 'The 0.0001 reconciliation residual is retained, not folded into a computed base.');
        $this->assertSame('0.0033', (string) $line->settled, 'The computed base is untouched by the allocation.');
    }

    public function test_an_ineligible_component_receives_nothing_and_does_not_dilute_the_others(): void
    {
        $allocation = $this->allocate('0.90', [AllocationShare::eligible('a', '100'), AllocationShare::ineligible('b', 'EXEMPT'), AllocationShare::eligible('c', '100'), AllocationShare::ineligible('d', 'BASE_UNKNOWN')]);

        $this->assertSame(['a' => '0.4500', 'b' => '0.0000', 'c' => '0.4500', 'd' => '0.0000'], $this->amounts($allocation));
        $this->assertSame(['EXEMPT', 'BASE_UNKNOWN'], [$allocation->for('b')->reason, $allocation->for('d')->reason]);
        $this->assertFalse($allocation->for('b')->eligible);
        $this->assertSame('0.0000', (string) $allocation->for('b')->allocated);
        $this->assertSame('0', (string) $allocation->for('b')->raw->getNumerator());
    }

    public function test_a_zero_weight_component_takes_nothing_even_when_a_quantum_is_left_over(): void
    {
        $allocation = $this->allocate('0.01', [AllocationShare::eligible('a', '0'), AllocationShare::eligible('b', '1'), AllocationShare::eligible('c', '1')]);

        $this->assertSame(['a' => '0.0000', 'b' => '0.0100', 'c' => '0.0000'], $this->amounts($allocation));
    }

    public function test_a_cap_fixes_a_component_and_the_rest_is_divided_among_the_others(): void
    {
        $allocation = $this->allocate('1.00', [AllocationShare::eligible('a', '1', '0.20'), AllocationShare::eligible('b', '1'), AllocationShare::eligible('c', '1')]);

        $this->assertSame(['a' => '0.2000', 'b' => '0.4000', 'c' => '0.4000'], $this->amounts($allocation));
        $this->assertTrue($allocation->for('a')->atCap);
        $this->assertFalse($allocation->for('b')->atCap);
        $this->assertSame('1.0000', (string) $allocation->sum());

        // A cap is an upper bound: 0.205 in two places floors to 0.20, and a cap that is not reached does nothing.
        $floored = $this->allocate('1.00', [AllocationShare::eligible('a', '1', '0.205'), AllocationShare::eligible('b', '1')]);
        $this->assertSame(['a' => '0.2000', 'b' => '0.8000'], $this->amounts($floored));
        $loose = $this->allocate('1.00', [AllocationShare::eligible('a', '1', '9.99'), AllocationShare::eligible('b', '1')]);
        $this->assertSame(['a' => '0.5000', 'b' => '0.5000'], $this->amounts($loose));
        // Capping cascades: once a is fixed the others' shares grow and may hit their own caps.
        $cascade = $this->allocate('1.00', [AllocationShare::eligible('a', '1', '0.10'), AllocationShare::eligible('b', '1', '0.30'), AllocationShare::eligible('c', '1')]);
        $this->assertSame(['a' => '0.1000', 'b' => '0.3000', 'c' => '0.6000'], $this->amounts($cascade));
    }

    public function test_an_amount_the_eligible_components_cannot_hold_is_refused_never_given_to_an_ineligible_one(): void
    {
        $this->refused(fn () => $this->allocate('1.00', [AllocationShare::eligible('a', '1', '0.10'), AllocationShare::eligible('b', '1', '0.10'), AllocationShare::ineligible('c', 'EXEMPT')]), 'ALLOCATION_CAPACITY_EXCEEDED');
        $this->assertSame(['a' => '0.2000'], $this->amounts($this->allocate('0.20', [AllocationShare::eligible('a', '1', '0.20')])), 'Exactly the capacity is allowed.');
    }

    public function test_invalid_and_incomplete_inputs_are_refused_with_stable_codes(): void
    {
        $ok = $this->equal(2);
        foreach (['1.005', '0.001', 'abc', '1e2', '', '1,00', ' 1.00', '1.'] as $bad) {
            $this->refused(fn () => $this->allocate($bad, $ok), 'ALLOCATION_TOTAL_INVALID');
        }
        $this->refused(fn () => $this->allocate('1000000000000000', $ok), 'ALLOCATION_TOTAL_INVALID');
        $this->refused(fn () => $this->allocate('1.00', []), 'ALLOCATION_NO_ELIGIBLE_COMPONENT');
        $this->refused(fn () => $this->allocate('0.00', []), 'ALLOCATION_NO_ELIGIBLE_COMPONENT');
        $this->refused(fn () => $this->allocate('1.00', [AllocationShare::ineligible('a', 'EXEMPT')]), 'ALLOCATION_NO_ELIGIBLE_COMPONENT');
        $this->refused(fn () => $this->allocate('1.00', [AllocationShare::eligible('a', '0'), AllocationShare::eligible('b', '0')]), 'ALLOCATION_NO_WEIGHT');
        $this->refused(fn () => $this->allocate('1.00', [AllocationShare::eligible('a', '1'), AllocationShare::eligible('a', '2')]), 'ALLOCATION_COMPONENT_INVALID');
        $this->refused(fn () => $this->allocate('1.00', ['a']), 'ALLOCATION_COMPONENT_INVALID');
        foreach ([fn () => AllocationShare::eligible('', '1'), fn () => AllocationShare::eligible(' a', '1'), fn () => AllocationShare::eligible('a', '-1'), fn () => AllocationShare::eligible('a', 'x'),
            fn () => AllocationShare::eligible('a', '1e3'), fn () => AllocationShare::eligible('a', '0.0000000001'), fn () => AllocationShare::eligible('a', '1', '-5'),
            fn () => AllocationShare::eligible('a', '1', '0.00001'), fn () => AllocationShare::ineligible('a', '  ')] as $build) {
            $this->refused($build, 'ALLOCATION_COMPONENT_INVALID');
        }
    }

    public function test_the_evidence_keeps_weights_raw_shares_and_residuals_as_exact_fractions(): void
    {
        $allocation = $this->allocate('1.00', $this->equal(3));
        $a = $allocation->for('a')->toArray();
        $b = $allocation->for('b')->toArray();

        $this->assertSame(['numerator' => '1', 'denominator' => '3'], $a['raw_share']);
        $this->assertSame('100', $a['weight'], 'weights are carried exactly as given');
        $this->assertSame(['numerator' => '1', 'denominator' => '150'], $a['residual'], '0.34 − 1/3 = 17/50 − 1/3');
        $this->assertSame(['numerator' => '-1', 'denominator' => '300'], $b['residual'], '0.33 − 1/3');
        $document = $allocation->toArray();
        $this->assertSame(['FR4.2', 'LARGEST_REMAINDER', 'KEY_ASCENDING', 1, 2, '1.0000'], [$document['calculation_version'], $document['method'], $document['tie_policy'], $document['currency_id'], $document['quantum_places'], $document['total']]);
        $this->assertCount(3, $document['shares']);
        $this->assertSame(['a', 'b', 'c'], array_column($document['shares'], 'key'));
        // Residuals of a complete allocation net to zero: nothing is created or lost.
        $net = BigRational::zero();
        foreach ($allocation->shares as $share) {
            $net = $net->plus($share->residual());
        }
        $this->assertTrue($net->isZero());
    }

    public function test_replaying_the_same_allocation_gives_the_same_result_and_a_changed_input_changes_it(): void
    {
        $first = $this->allocate('1.00', $this->equal(3));
        $second = $this->allocate('1.00', $this->equal(3));

        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertNotSame($first->fingerprint(), $this->allocate('1.01', $this->equal(3))->fingerprint());
        $this->assertNotSame($first->fingerprint(), $this->allocate('1.00', $this->equal(3), 3)->fingerprint());
        $this->assertNotSame($first->fingerprint(), $this->allocate('1.00', [AllocationShare::eligible('a', '100'), AllocationShare::eligible('b', '100'), AllocationShare::eligible('c', '101')])->fingerprint());
        $this->assertSame(64, strlen($first->fingerprint()));
    }

    public function test_every_allocation_conserves_its_total_exactly_for_many_deterministic_inputs(): void
    {
        mt_srand(20261016);
        for ($case = 0; $case < 300; $case++) {
            $places = mt_rand(0, 4);
            $count = mt_rand(1, 7);
            $total = BigDecimal::ofUnscaledValue(mt_rand(0, 999999), $places)->multipliedBy(mt_rand(0, 1) === 1 ? -1 : 1);
            $shares = [];
            for ($i = 0; $i < $count; $i++) {
                $shares[] = mt_rand(0, 5) === 0 ? AllocationShare::ineligible("k{$i}", 'EXEMPT') : AllocationShare::eligible("k{$i}", (string) mt_rand(1, 99999).'.'.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT));
            }
            $hasEligible = count(array_filter($shares, fn (AllocationShare $share): bool => $share->eligible)) > 0;
            if (! $hasEligible && ! $total->isZero()) {
                $this->refused(fn () => $this->allocate((string) $total, $shares, $places), 'ALLOCATION_NO_ELIGIBLE_COMPONENT');

                continue;
            }
            $allocation = $this->allocate((string) $total, $shares, $places);
            $this->assertTrue($allocation->sum()->isEqualTo($total), "case {$case}: total");
            $sum = BigDecimal::zero();
            foreach ($allocation->shares as $share) {
                $sum = $sum->plus($share->allocated);
                $this->assertTrue($share->allocated->isZero() || $share->allocated->getSign() === ($total->isNegative() ? -1 : 1), "case {$case}: no sign flip");
                $this->assertLessThanOrEqual(0, $share->allocated->abs()->compareTo($total->abs()), "case {$case}: never more than the total");
                if (! $share->eligible) {
                    $this->assertTrue($share->allocated->isZero(), "case {$case}: ineligible gets nothing");
                } else {
                    $this->assertTrue($share->residual()->abs()->isLessThan(BigRational::of(BigDecimal::ofUnscaledValue(1, $places))), "case {$case}: within one quantum of its exact share");
                }
            }
            $this->assertTrue($sum->isEqualTo($total), "case {$case}: shares sum");
        }
    }
}
