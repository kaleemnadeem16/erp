<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Financial\Controls\ControlBasis;
use App\Modules\Sales\Support\Financial\Controls\ControlEvidenceProvider;
use App\Modules\Sales\Support\Financial\Controls\ControlMode;
use App\Modules\Sales\Support\Financial\Controls\ControlOutcome;
use App\Modules\Sales\Support\Financial\Controls\DiscretionaryInput;
use App\Modules\Sales\Support\Financial\Controls\EvaluateSettledNetControls;
use App\Modules\Sales\Support\Financial\Controls\GovernedReference;
use App\Modules\Sales\Support\Financial\Controls\NetControl;
use App\Modules\Sales\Support\Financial\Controls\NetControlEvaluation;
use App\Modules\Sales\Support\Financial\Controls\NetControlKind as Kind;
use App\Modules\Sales\Support\Financial\Controls\NetControlSet;
use App\Modules\Sales\Support\Financial\Controls\SettledNetFacts;
use App\Modules\Sales\Support\Financial\Controls\UnavailableControlEvidence;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use App\Modules\Sales\Support\Fx\FxLegEvidence;
use App\Modules\Sales\Support\Fx\FxPolicy;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PriceCandidate;
use App\Modules\Sales\Support\Pricing\PricingResult;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * FR4 — final-net controls (ADR 0116, 0120): PASS/WARN/FAIL/UNEVALUABLE over exact settled amounts, with figures that can be checked by hand. Pure: no database,
 * so nothing is persisted or posted. Everything here uses controlled fixtures; the real cost-blind cost source is a separately authorised adapter.
 */
class EvaluateSettledNetControlsTest extends TestCase
{
    private const PKR = 1;

    private const USD = 2;

    private const DATE = '2026-10-16';

    private function facts(string $net = '8000', ?string $cost = '7500', string $reference = '10000', ?DiscretionaryInput $input = null, string $quantity = '1', array $fx = [], ?RoundingPolicy $policy = null, int $company = 1): SettledNetFacts
    {
        return new SettledNetFacts($company, self::PKR, $net, $quantity, self::DATE, new GovernedReference($reference, self::PKR, 'POLICY_BASE'), $input ?? DiscretionaryInput::none(),
            $cost === null ? null : FinancialResult::complete($cost, self::PKR), $fx, $policy);
    }

    private function set(NetControl ...$controls): NetControlSet
    {
        return new NetControlSet(1, 3, array_values($controls));
    }

    private function control(string $id, Kind $kind, string $value, ControlMode $mode = ControlMode::Mandatory, ?int $currency = null, ControlBasis $basis = ControlBasis::Line): NetControl
    {
        return new NetControl($id, 1, $kind, $mode, $value, $kind->isPercent() ? null : ($currency ?? self::PKR), $basis);
    }

    private function judged(SettledNetFacts $facts, NetControl ...$controls): NetControlEvaluation
    {
        return (new EvaluateSettledNetControls)($facts, $this->set(...$controls));
    }

    private function outcomeOf(NetControlEvaluation $evaluation, string $id): string
    {
        return $evaluation->result($id)->outcome->value;
    }

    private function refused(callable $call, string $code): DomainInvariantException
    {
        try {
            $call();
            $this->fail("Expected a refusal with {$code}.");
        } catch (DomainInvariantException $refusal) {
            $this->assertSame($code, $refusal->reasonCode);

            return $refusal;
        }
    }

    private function usdToPkr(int $company = 1, string $date = self::DATE, string $rate = '280'): FxBundleEvidence
    {
        $requested = FinancialDate::explicit($date, 'Asia/Karachi', CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));

        return new FxBundleEvidence($company, FxBundleEvidence::ROUTE_COMPANY_BASE_BRIDGE, self::USD, self::PKR, self::PKR, $requested, FxPolicy::builtInExactDate(),
            new FxLegEvidence(self::USD, self::PKR, $rate.'.000000000', $date, $date, 0, false, 'manual', 11), FxLegEvidence::identity(self::PKR, $date));
    }

    public function test_the_worked_example_reference_10000_final_8000_cost_7500(): void
    {
        $facts = $this->facts();

        // Margin 500 on 8000 = 6.25%: exactly 6.25 passes (at the limit), 6.2501 fails.
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('m', Kind::MinMarginPercent, '5')), 'm'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('m', Kind::MinMarginPercent, '6.25')), 'm'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('m', Kind::MinMarginPercent, '6.2501')), 'm'));
        // Aggregate reduction 2000 of 10000 = 20%.
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('a', Kind::AggregateReductionPercent, '15')), 'a'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('a', Kind::AggregateReductionPercent, '20')), 'a'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('a', Kind::AggregateReductionAmount, '1999.9999')), 'a'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('a', Kind::AggregateReductionAmount, '2000')), 'a'));
        // Floor at the settled net passes, a cent over fails; a margin amount of 500 is met exactly.
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('f', Kind::Floor, '8000')), 'f'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('f', Kind::Floor, '8000.01')), 'f'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('ma', Kind::MinMarginAmount, '500')), 'ma'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('ma', Kind::MinMarginAmount, '500.0001')), 'ma'));
    }

    public function test_the_accepted_floor_examples_final_650_passes_and_final_585_fails_a_600_floor(): void
    {
        $floor = $this->control('f', Kind::Floor, '600');

        $this->assertSame('PASS', $this->outcomeOf($this->judged($this->facts('650', '400', '700'), $floor), 'f'));
        $fail = $this->judged($this->facts('585', '400', '700'), $floor);
        $this->assertSame('FAIL', $this->outcomeOf($fail, 'f'));
        $this->assertTrue($fail->blocksIssue());
        $this->assertSame('FAIL', $fail->outcome()->value);
    }

    public function test_floor_and_margin_are_independent_checks_and_nothing_is_clamped(): void
    {
        $floor = $this->control('floor', Kind::Floor, '600');
        $margin = $this->control('margin', Kind::MinMarginPercent, '10');

        // Above the floor but a thin margin: floor passes, margin fails.
        $a = $this->judged($this->facts('650', '640', '700'), $floor, $margin);
        $this->assertSame(['PASS', 'FAIL'], [$this->outcomeOf($a, 'floor'), $this->outcomeOf($a, 'margin')]);
        // Below the floor with a healthy margin: floor fails, margin passes.
        $b = $this->judged($this->facts('585', '100', '700'), $floor, $margin);
        $this->assertSame(['FAIL', 'PASS'], [$this->outcomeOf($b, 'floor'), $this->outcomeOf($b, 'margin')]);
        $this->assertSame('585.0000', (string) $this->facts('585', '100')->finalNet, 'The evaluator never changes the settled net.');
    }

    public function test_advisory_and_mandatory_violations_differ_and_precedence_is_fail_then_unevaluable_then_warn(): void
    {
        $facts = $this->facts('585', '100', '700');
        $mandatoryFloor = $this->control('mf', Kind::Floor, '600');
        $advisoryFloor = $this->control('af', Kind::Floor, '600', ControlMode::Advisory);

        $warn = $this->judged($facts, $advisoryFloor);
        $this->assertSame(['WARN', 'WARN', false, true], [$this->outcomeOf($warn, 'af'), $warn->outcome()->value, $warn->blocksIssue(), $warn->requiresReview()]);

        $unevaluable = $this->judged($this->facts('585', null, '700'), $this->control('mm', Kind::MinMarginPercent, '10'), $advisoryFloor);
        $this->assertSame('UNEVALUABLE', $unevaluable->outcome()->value, 'Missing evidence outranks a warning.');
        $this->assertTrue($unevaluable->blocksIssue());

        $all = $this->judged($this->facts('585', null, '700'), $mandatoryFloor, $this->control('mm', Kind::MinMarginPercent, '10'), $advisoryFloor);
        $this->assertSame('FAIL', $all->outcome()->value, 'A known violation outranks everything.');

        $pass = $this->judged($this->facts('650', '400', '700'), $this->control('f', Kind::Floor, '600'));
        $this->assertSame(['PASS', false, false], [$pass->outcome()->value, $pass->blocksIssue(), $pass->requiresReview()]);
        $this->assertSame(ControlOutcome::Fail, ControlOutcome::cases()[2]);
    }

    public function test_missing_unknown_and_partial_cost_are_never_zero_or_a_pass(): void
    {
        $margin = $this->control('m', Kind::MinMarginPercent, '1');
        $amount = $this->control('a', Kind::MinMarginAmount, '0');
        $unknown = FinancialResult::unknown([['code' => 'COST_UNKNOWN']]);
        $partial = FinancialResult::partial('100', self::PKR, [['code' => 'COST_PARTIAL']]);
        $cases = ['not provided' => [null, 'COST_NOT_PROVIDED'], 'unknown' => [$unknown, 'COST_UNKNOWN'], 'partial (a known subtotal is not a cost)' => [$partial, 'COST_PARTIAL']];

        foreach ($cases as $label => [$cost, $reason]) {
            $facts = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, $cost);
            $evaluation = $this->judged($facts, $margin, $amount);
            $this->assertSame('UNEVALUABLE', $evaluation->outcome()->value, $label);
            $this->assertTrue($evaluation->blocksIssue(), $label);
            $this->assertSame([$reason, $reason], [$evaluation->result('m')->reason, $evaluation->result('a')->reason], $label);
            $this->assertSame([], $evaluation->result('m')->operands, 'No operand was invented.');
        }
        // An advisory control with missing cost is stated UNEVALUABLE, does not block, and still asks for review.
        $advisory = $this->judged(new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, $unknown), $this->control('x', Kind::MinMarginPercent, '1', ControlMode::Advisory));
        $this->assertSame(['UNEVALUABLE', false, true], [$advisory->outcome()->value, $advisory->blocksIssue(), $advisory->requiresReview()]);
    }

    public function test_a_genuine_complete_zero_cost_is_evidence_and_is_used(): void
    {
        $facts = new SettledNetFacts(1, self::PKR, '500', '1', self::DATE, null, null, FinancialResult::complete('0', self::PKR));

        $evaluation = $this->judged($facts, $this->control('p', Kind::MinMarginPercent, '100'), $this->control('a', Kind::MinMarginAmount, '500'));

        $this->assertSame(['PASS', 'PASS', 'PASS'], [$this->outcomeOf($evaluation, 'p'), $this->outcomeOf($evaluation, 'a'), $evaluation->outcome()->value], '100% margin at zero cost.');
    }

    public function test_zero_net_has_an_undefined_percentage_margin_and_a_zero_reference_has_an_undefined_percentage_reduction(): void
    {
        $zeroNet = new SettledNetFacts(1, self::PKR, '0', '1', self::DATE, null, null, FinancialResult::complete('0', self::PKR));
        $evaluation = $this->judged($zeroNet, $this->control('p', Kind::MinMarginPercent, '10'), $this->control('a', Kind::MinMarginAmount, '0'), $this->control('f', Kind::Floor, '0'));

        $this->assertSame(['UNEVALUABLE', 'MARGIN_UNDEFINED_AT_ZERO_NET'], [$this->outcomeOf($evaluation, 'p'), $evaluation->result('p')->reason]);
        $this->assertSame(['PASS', 'PASS'], [$this->outcomeOf($evaluation, 'a'), $this->outcomeOf($evaluation, 'f')], 'Amount controls are still well-defined at zero net.');

        $zeroReference = $this->facts('0', '0', '0');
        $this->assertSame('REFERENCE_ZERO_PERCENT_UNDEFINED', $this->judged($zeroReference, $this->control('r', Kind::AggregateReductionPercent, '10'))->result('r')->reason);
        $this->assertSame('PASS', $this->outcomeOf($this->judged($zeroReference, $this->control('r', Kind::AggregateReductionAmount, '0')), 'r'));
    }

    public function test_the_specific_discretionary_input_and_the_aggregate_reduction_are_judged_separately(): void
    {
        // Reference 10000, final 8000: the seller entered 10% themselves while an automatic benefit supplied the rest. Aggregate 20%.
        $facts = $this->facts('8000', '7000', '10000', DiscretionaryInput::percent('10'));
        $input = $this->control('in', Kind::InputReductionPercent, '12');
        $aggregate = $this->control('ag', Kind::AggregateReductionPercent, '15');

        $evaluation = $this->judged($facts, $input, $aggregate);

        $this->assertSame(['PASS', 'FAIL'], [$this->outcomeOf($evaluation, 'in'), $this->outcomeOf($evaluation, 'ag')]);
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($this->facts('8000', '7000', '10000', DiscretionaryInput::percent('12.5')), $input), 'in'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($this->facts('8000', '7000', '10000', DiscretionaryInput::percent('12')), $input), 'in'), 'At the limit.');
    }

    public function test_percentage_and_absolute_inputs_and_limits_are_each_measured_against_the_reference(): void
    {
        $percentLimit = $this->control('p', Kind::InputReductionPercent, '10');
        $amountLimit = $this->control('a', Kind::InputReductionAmount, '1000');

        // A 1,200 absolute discount is 12% of the 10,000 reference: over both limits.
        $absolute = $this->facts('8800', '1', '10000', DiscretionaryInput::amount('1200', self::PKR));
        $this->assertSame(['FAIL', 'FAIL'], [$this->outcomeOf($this->judged($absolute, $percentLimit, $amountLimit), 'p'), $this->outcomeOf($this->judged($absolute, $percentLimit, $amountLimit), 'a')]);
        $within = $this->facts('9000', '1', '10000', DiscretionaryInput::amount('1000', self::PKR));
        $this->assertSame(['PASS', 'PASS'], [$this->outcomeOf($this->judged($within, $percentLimit, $amountLimit), 'p'), $this->outcomeOf($this->judged($within, $percentLimit, $amountLimit), 'a')]);
        // A 9% input is inside the percentage limit and (900) inside the amount limit; the two controls remain separate results.
        $percentInput = $this->facts('9100', '1', '10000', DiscretionaryInput::percent('9'));
        $both = $this->judged($percentInput, $percentLimit, $amountLimit);
        $this->assertSame(['PASS', 'PASS'], [$this->outcomeOf($both, 'p'), $this->outcomeOf($both, 'a')]);
        $this->assertSame(['numerator' => '900', 'denominator' => '1'], $both->result('a')->operands['reduction']);
    }

    public function test_no_discretionary_input_passes_input_controls_and_an_unstated_one_does_not(): void
    {
        $control = $this->control('in', Kind::InputReductionPercent, '5');

        $none = $this->judged($this->facts('8000', '1', '10000', DiscretionaryInput::none()), $control);
        $this->assertSame(['PASS', 'NO_DISCRETIONARY_INPUT'], [$none->outcome()->value, $none->result('in')->reason]);

        $unstated = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, new GovernedReference('10000', self::PKR, 'POLICY_BASE'), null, null);
        $this->assertSame(['UNEVALUABLE', 'DISCRETIONARY_INPUT_NOT_PROVIDED'], [$this->judged($unstated, $control)->outcome()->value, $this->judged($unstated, $control)->result('in')->reason]);
        $noReference = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, DiscretionaryInput::none(), null);
        $this->assertSame('REFERENCE_MISSING', $this->judged($noReference, $control)->result('in')->reason);
        $this->assertSame('REFERENCE_MISSING', $this->judged($noReference, $this->control('ag', Kind::AggregateReductionPercent, '5'))->result('ag')->reason);
    }

    public function test_a_price_increase_over_the_reference_is_no_reduction(): void
    {
        $evaluation = $this->judged($this->facts('11000', '1', '10000'), $this->control('ag', Kind::AggregateReductionPercent, '0'), $this->control('aa', Kind::AggregateReductionAmount, '0'));

        $this->assertSame('PASS', $evaluation->outcome()->value);
        $this->assertSame(['numerator' => '0', 'denominator' => '1'], $evaluation->result('ag')->operands['reduction']);
    }

    public function test_per_unit_amounts_are_multiplied_by_the_exact_priced_quantity(): void
    {
        // Floor of 3.00 per unit over 0.333 units is exactly 0.999 for the line.
        $floor = $this->control('f', Kind::Floor, '3', basis: ControlBasis::PerUnit);

        $this->assertSame('PASS', $this->outcomeOf($this->judged($this->facts('0.9990', '0', '1', null, '0.333'), $floor), 'f'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($this->facts('0.9989', '0', '1', null, '0.333'), $floor), 'f'));
        $perUnitInput = $this->judged($this->facts('9000', '1', '10000', DiscretionaryInput::amount('250', self::PKR, ControlBasis::PerUnit), '4'), $this->control('in', Kind::InputReductionAmount, '1000'));
        $this->assertSame('PASS', $perUnitInput->outcome()->value, '250 per unit over 4 units is exactly the 1000 limit.');
    }

    public function test_cost_and_limits_in_another_currency_need_pinned_fx_for_the_pricing_date(): void
    {
        $usdCost = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD), [$this->usdToPkr()]);
        $margin = $this->control('m', Kind::MinMarginAmount, '1000');

        // 25 USD at 280 = 7000 PKR: margin exactly 1000.
        $this->assertSame('PASS', $this->outcomeOf($this->judged($usdCost, $margin), 'm'));
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($usdCost, $this->control('m', Kind::MinMarginAmount, '1000.0001')), 'm'));
        // A USD floor: 30 USD = 8400 PKR, above the 8000 net.
        $this->assertSame('FAIL', $this->outcomeOf($this->judged($usdCost, $this->control('f', Kind::Floor, '30', currency: self::USD)), 'f'));

        $missing = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD));
        $this->assertSame(['UNEVALUABLE', 'FX_EVIDENCE_MISSING'], [$this->judged($missing, $margin)->outcome()->value, $this->judged($missing, $margin)->result('m')->reason]);
        $reverse = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD), [$this->pkrToUsd()]);
        $this->assertSame('FX_EVIDENCE_MISSING', $this->judged($reverse, $margin)->result('m')->reason, 'A reciprocal is not substituted.');
        foreach (['another date' => $this->usdToPkr(date: '2026-10-15'), 'another company' => $this->usdToPkr(company: 2)] as $why => $bundle) {
            $stale = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD), [$bundle]);
            $this->assertSame('FX_EVIDENCE_INCOMPATIBLE', $this->judged($stale, $margin)->result('m')->reason, $why);
        }
        $conflicting = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD), [$this->usdToPkr(), $this->usdToPkr(rate: '300')]);
        $this->assertSame('FX_EVIDENCE_AMBIGUOUS', $this->judged($conflicting, $margin)->result('m')->reason);
        $repeated = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, FinancialResult::complete('25', self::USD), [$this->usdToPkr(), $this->usdToPkr()]);
        $this->assertSame('PASS', $this->judged($repeated, $margin)->outcome()->value, 'The same evidence twice is not a conflict.');
    }

    private function pkrToUsd(): FxBundleEvidence
    {
        $requested = FinancialDate::explicit(self::DATE, 'Asia/Karachi', CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));

        return new FxBundleEvidence(1, FxBundleEvidence::ROUTE_COMPANY_BASE_BRIDGE, self::PKR, self::USD, self::PKR, $requested, FxPolicy::builtInExactDate(),
            FxLegEvidence::identity(self::PKR, self::DATE), new FxLegEvidence(self::USD, self::PKR, '280.000000000', self::DATE, self::DATE, 0, false, 'manual', 11));
    }

    public function test_a_reference_in_another_currency_is_converted_exactly(): void
    {
        // Reference 40 USD = 11,200 PKR; final 8,000 PKR is a reduction of 3,200 = 28.5714…% — over a 28% limit, under 29%.
        $facts = new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, new GovernedReference('40', self::USD, 'POLICY_BASE'), null, null, [$this->usdToPkr()]);

        $this->assertSame('FAIL', $this->outcomeOf($this->judged($facts, $this->control('ag', Kind::AggregateReductionPercent, '28')), 'ag'));
        $this->assertSame('PASS', $this->outcomeOf($this->judged($facts, $this->control('ag', Kind::AggregateReductionPercent, '29')), 'ag'));
        $this->assertSame(['numerator' => '3200', 'denominator' => '1'], $this->judged($facts, $this->control('ag', Kind::AggregateReductionPercent, '29'))->result('ag')->operands['reduction']);
    }

    public function test_controls_written_for_another_rounding_policy_are_not_comparable(): void
    {
        $policy = new RoundingPolicy(1, 1, 1, 4, SettlementRounding::HalfUp, null);
        $other = new RoundingPolicy(1, 2, 2, 2, SettlementRounding::HalfUp, null);
        $set = new NetControlSet(1, 3, [$this->control('f', Kind::Floor, '600')], $policy->fingerprint());

        $matching = (new EvaluateSettledNetControls)($this->facts('650', '1', '700', null, '1', [], $policy), $set);
        $this->assertSame('PASS', $matching->outcome()->value);
        $mismatch = (new EvaluateSettledNetControls)($this->facts('650', '1', '700', null, '1', [], $other), $set);
        $this->assertSame(['UNEVALUABLE', 'POLICY_VERSION_INCOMPATIBLE'], [$mismatch->outcome()->value, $mismatch->result('f')->reason]);
        $absent = (new EvaluateSettledNetControls)($this->facts('650', '1', '700'), $set);
        $this->assertSame('POLICY_EVIDENCE_MISSING', $absent->result('f')->reason);
        $this->assertTrue($absent->blocksIssue());
    }

    public function test_a_different_company_is_refused_not_evaluated(): void
    {
        $this->refused(fn () => (new EvaluateSettledNetControls)($this->facts(company: 2), $this->set($this->control('f', Kind::Floor, '1'))), 'CONTROL_CONTEXT_MISMATCH');
    }

    public function test_an_empty_control_set_passes_and_says_it_judged_nothing(): void
    {
        $evaluation = (new EvaluateSettledNetControls)($this->facts(), $this->set());

        $this->assertSame(['PASS', 0, false], [$evaluation->outcome()->value, $evaluation->toInternalArray()['evaluated'], $evaluation->blocksIssue()]);
    }

    public function test_malformed_controls_and_facts_are_refused_with_fixed_messages(): void
    {
        $secret = '98765.4321';
        foreach ([fn () => new NetControl('', 1, Kind::Floor, ControlMode::Mandatory, $secret, 1), fn () => new NetControl(' x', 1, Kind::Floor, ControlMode::Mandatory, $secret, 1),
            fn () => new NetControl('x', 0, Kind::Floor, ControlMode::Mandatory, $secret, 1), fn () => new NetControl('x', 1, Kind::Floor, ControlMode::Mandatory, '-'.$secret, 1),
            fn () => new NetControl('x', 1, Kind::Floor, ControlMode::Mandatory, $secret, null), fn () => new NetControl('x', 1, Kind::Floor, ControlMode::Mandatory, $secret.'9', 1),
            fn () => new NetControl('x', 1, Kind::MinMarginPercent, ControlMode::Mandatory, '100.0001'), fn () => new NetControl('x', 1, Kind::MinMarginPercent, ControlMode::Mandatory, '10', 1),
            fn () => new NetControl('x', 1, Kind::MinMarginPercent, ControlMode::Mandatory, '1e1'), fn () => new NetControl('x', 1, Kind::MinMarginPercent, ControlMode::Mandatory, '10.12345')] as $build) {
            $refusal = $this->refused($build, 'CONTROL_INVALID');
            $this->assertStringNotContainsString('98765', $refusal->getMessage());
        }
        $this->refused(fn () => new NetControlSet(1, 1, [$this->control('a', Kind::Floor, '1'), $this->control('a', Kind::Floor, '2')]), 'CONTROL_INVALID');
        $this->refused(fn () => new NetControlSet(1, 0, []), 'CONTROL_INVALID');
        $this->refused(fn () => new SettledNetFacts(1, self::PKR, '8000', '0', self::DATE), 'NET_FACTS_INVALID');
        $this->refused(fn () => new SettledNetFacts(1, self::PKR, '8000', '1.0000000001', self::DATE), 'NET_FACTS_INVALID');
        $this->refused(fn () => new SettledNetFacts(1, self::PKR, '8000', '1', self::DATE, null, null, null, ['x']), 'NET_FACTS_INVALID');
        $this->refused(fn () => new SettledNetFacts(1, self::PKR, '-1', '1', self::DATE), 'NEGATIVE_AMOUNT');
        $this->refused(fn () => new SettledNetFacts(1, self::PKR, '8000', '1', '2026-02-30'), 'FINANCIAL_DATE_INVALID');
        $this->refused(fn () => new GovernedReference('1', self::PKR, ' '), 'NET_FACTS_INVALID');
        $this->refused(fn () => DiscretionaryInput::percent('100.5'), 'NET_FACTS_INVALID');
        $this->refused(fn () => DiscretionaryInput::percent('-1'), 'NET_FACTS_INVALID');
    }

    public function test_results_are_repeatable_and_the_fingerprints_track_content_not_order(): void
    {
        $controls = [$this->control('a', Kind::Floor, '600'), $this->control('b', Kind::MinMarginPercent, '5'), $this->control('c', Kind::AggregateReductionPercent, '30')];
        $first = $this->judged($this->facts('650', '400', '700'), ...$controls);
        $second = $this->judged($this->facts('650', '400', '700'), ...$controls);

        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame($first->toInternalArray(), $second->toInternalArray());
        $this->assertSame((new NetControlSet(1, 3, $controls))->fingerprint(), (new NetControlSet(1, 3, array_reverse($controls)))->fingerprint(), 'A set is its controls, not their order.');
        $this->assertNotSame($first->fingerprint(), $this->judged($this->facts('650', '401', '700'), ...$controls)->fingerprint());
        $this->assertNotSame((new NetControlSet(1, 3, $controls))->fingerprint(), (new NetControlSet(1, 4, $controls))->fingerprint());
        $this->assertSame(64, strlen($first->fingerprint()));
    }

    public function test_the_consumer_safe_view_carries_outcomes_only_and_no_protected_operand(): void
    {
        $cost = '7123.4567';
        $facts = $this->facts('8000', $cost, '10000');
        $evaluation = $this->judged($facts, $this->control('c-17', Kind::MinMarginPercent, '4.321'), $this->control('c-18', Kind::Floor, '8765.4321'), $this->control('c-19', Kind::AggregateReductionPercent, '15', ControlMode::Advisory));

        $view = $evaluation->consumerSafe();
        $payload = json_encode($view->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame(['outcome' => 'FAIL', 'blocks_issue' => true, 'requires_review' => true], array_intersect_key($view->toArray(), array_flip(['outcome', 'blocks_issue', 'requires_review'])));
        $this->assertSame([['c-17', 'PASS'], ['c-18', 'FAIL'], ['c-19', 'WARN']], array_map(fn (array $c): array => [$c['ref'], $c['outcome']], $view->controls));
        foreach (['7123', '4567', '8765', '4321', '4.321', '8000', '10000', '15', 'cost', 'margin', 'floor', 'FLOOR', 'MARGIN', 'BELOW', 'reason', 'operand', 'limit', 'fingerprint', 'numerator'] as $leak) {
            $this->assertStringNotContainsString($leak, $payload, "leaked '{$leak}'");
        }
        $this->assertSame(['outcome', 'blocks_issue', 'requires_review', 'controls'], array_keys($view->toArray()));
        // The internal evidence, by contrast, does carry them.
        $this->assertStringContainsString('"numerator"', json_encode($evaluation->toInternalArray()));
    }

    public function test_a_priced_line_becomes_facts_and_withheld_cost_is_not_smuggled_in(): void
    {
        $rule = new SettlementRule(self::PKR, new CurrencyQuantum(2), RoundingMode::HALF_UP);
        $line = Arithmetic::settleLine('3000.00', '2.5', $rule);
        $source = new ResolvedSource(PricingSourceKind::DirectItem, 7, 'ITEM-7', 3, BigDecimal::of('2.5000'), FinancialResult::complete('5000', self::PKR));
        $priced = new PricingResult($source, new PriceCandidate('P1', 1, '3000.00', self::PKR), BigDecimal::of('2.5000'), $line, $source->cost);

        $facts = SettledNetFacts::fromPricing($priced, 1, self::DATE, new GovernedReference('8000', self::PKR, 'POLICY_BASE'), DiscretionaryInput::none());
        $this->assertSame(['7500.0000', '2.5000', self::PKR, Certainty::Complete], [(string) $facts->finalNet, (string) $facts->quantity, $facts->currencyId, $facts->cost->certainty]);
        $this->assertSame('PASS', $this->judged($facts, $this->control('m', Kind::MinMarginPercent, '30'))->outcome()->value, '2,500 on 7,500 is 33.3%.');

        $withheld = SettledNetFacts::fromPricing($priced->forReader(false), 1, self::DATE);
        $this->assertNull($withheld->cost, 'A result withheld from a reader carries no cost into an evaluation.');
        $this->assertSame('COST_NOT_PROVIDED', $this->judged($withheld, $this->control('m', Kind::MinMarginPercent, '30'))->result('m')->reason);
    }

    public function test_the_only_delivered_evidence_provider_states_that_no_cost_adapter_exists(): void
    {
        $provider = new UnavailableControlEvidence;
        $this->assertInstanceOf(ControlEvidenceProvider::class, $provider);
        $rule = new SettlementRule(self::PKR, new CurrencyQuantum(2), RoundingMode::HALF_UP);
        $source = new ResolvedSource(PricingSourceKind::Manual, null, 'x', null, BigDecimal::of('1'), FinancialResult::unknown([['code' => 'MANUAL_COST_NOT_PROVIDED']]));
        $priced = new PricingResult($source, new PriceCandidate('P', 1, '1', self::PKR), BigDecimal::of('1'), Arithmetic::settleLine('1', '1', $rule), $source->cost);
        $actor = (new \ReflectionClass(SalesActorContext::class))->newInstanceWithoutConstructor();

        $cost = $provider->costFor($actor, $priced);

        $this->assertSame([Certainty::Unknown, 'COST_ADAPTER_NOT_DELIVERED'], [$cost->certainty, $cost->reasons[0]['code']]);
    }
}
