<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Judges a line's final settled net against a company's final-net controls (FR4, ADR 0116, 0120). Pure, exact and persistence-free: all comparisons are
 * between exact rationals, nothing is rounded, and money in another currency is brought into the net's currency only through pinned FX evidence for the
 * pricing date. It decides nothing about approval, exceptions or issue — it reports outcomes and whether any mandatory control blocks. It checks no
 * permission: a guarded caller decides who may run it and which projection they receive.
 *
 * A control that cannot be evaluated is UNEVALUABLE, never a PASS and never zero: missing, unknown or partial cost; no governed reference; no stated
 * discretionary input; no or incompatible FX for another currency; a rounding policy the controls were not written for; percentage margin at zero net.
 * A genuine, COMPLETE zero cost is evidence and is used. At-the-limit values satisfy their control.
 */
final class EvaluateSettledNetControls
{
    public function __invoke(SettledNetFacts $facts, NetControlSet $set): NetControlEvaluation
    {
        if ($set->companyId !== $facts->companyId) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_CONTEXT_MISMATCH, 'The controls and the facts belong to different companies.', 'company');
        }
        $policyProblem = $this->policyProblem($facts, $set);

        $results = [];
        foreach ($set->controls as $control) {
            $results[] = $policyProblem !== null ? new ControlResult($control, ControlOutcome::Unevaluable, $policyProblem) : $this->evaluate($control, $facts);
        }

        return new NetControlEvaluation($set, $results);
    }

    private function policyProblem(SettledNetFacts $facts, NetControlSet $set): ?string
    {
        if ($set->roundingFingerprint === null) {
            return null;
        }
        if ($facts->roundingPolicy === null) {
            return 'POLICY_EVIDENCE_MISSING';
        }

        return hash_equals($set->roundingFingerprint, $facts->roundingPolicy->fingerprint()) ? null : 'POLICY_VERSION_INCOMPATIBLE';
    }

    private function evaluate(NetControl $control, SettledNetFacts $facts): ControlResult
    {
        try {
            return match ($control->kind) {
                NetControlKind::Floor => $this->floor($control, $facts),
                NetControlKind::MinMarginAmount => $this->marginAmount($control, $facts),
                NetControlKind::MinMarginPercent => $this->marginPercent($control, $facts),
                NetControlKind::InputReductionPercent, NetControlKind::InputReductionAmount => $this->reduction($control, $facts, true),
                NetControlKind::AggregateReductionPercent, NetControlKind::AggregateReductionAmount => $this->reduction($control, $facts, false),
            };
        } catch (CannotEvaluate $why) {
            return new ControlResult($control, ControlOutcome::Unevaluable, $why->getMessage());
        }
    }

    private function floor(NetControl $control, SettledNetFacts $facts): ControlResult
    {
        $net = BigRational::of($facts->finalNet);
        $floor = $this->limitAmount($control, $facts);

        return $this->judge($control, $net->isGreaterThanOrEqualTo($floor), 'FLOOR_MET', 'BELOW_FLOOR', ['final_net' => $net, 'floor' => $floor]);
    }

    private function marginAmount(NetControl $control, SettledNetFacts $facts): ControlResult
    {
        $net = BigRational::of($facts->finalNet);
        $cost = $this->cost($facts);
        $margin = $net->minus($cost);
        $minimum = $this->limitAmount($control, $facts);

        return $this->judge($control, $margin->isGreaterThanOrEqualTo($minimum), 'MARGIN_AMOUNT_MET', 'MARGIN_AMOUNT_BELOW_MINIMUM', ['final_net' => $net, 'cost' => $cost, 'margin' => $margin, 'minimum' => $minimum]);
    }

    private function marginPercent(NetControl $control, SettledNetFacts $facts): ControlResult
    {
        $net = BigRational::of($facts->finalNet);
        $cost = $this->cost($facts);
        if ($net->isZero()) {
            throw new CannotEvaluate('MARGIN_UNDEFINED_AT_ZERO_NET');
        }
        $margin = $net->minus($cost);
        $required = $net->multipliedBy($this->percent($control->value));

        return $this->judge($control, $margin->isGreaterThanOrEqualTo($required), 'MARGIN_PERCENT_MET', 'MARGIN_PERCENT_BELOW_MINIMUM', ['final_net' => $net, 'cost' => $cost, 'margin' => $margin, 'required_margin' => $required]);
    }

    private function reduction(NetControl $control, SettledNetFacts $facts, bool $specificInput): ControlResult
    {
        if ($facts->reference === null) {
            throw new CannotEvaluate('REFERENCE_MISSING');
        }
        $reference = $this->inNetCurrency(BigRational::of($facts->reference->amount), $facts->reference->currencyId, $facts);
        $net = BigRational::of($facts->finalNet);

        if ($specificInput) {
            $reduction = $this->inputReduction($facts, $reference);
            if ($reduction === null) {
                return new ControlResult($control, ControlOutcome::Pass, 'NO_DISCRETIONARY_INPUT');
            }
        } else {
            $reduction = $reference->isGreaterThan($net) ? $reference->minus($net) : BigRational::zero();
        }

        if ($control->kind->isPercent()) {
            if ($reference->isZero()) {
                throw new CannotEvaluate('REFERENCE_ZERO_PERCENT_UNDEFINED');
            }
            $allowed = $reference->multipliedBy($this->percent($control->value));
        } else {
            $allowed = $this->limitAmount($control, $facts);
        }

        return $this->judge($control, $reduction->isLessThanOrEqualTo($allowed), 'WITHIN_LIMIT', 'LIMIT_EXCEEDED', ['reference' => $reference, 'reduction' => $reduction, 'allowed' => $allowed]);
    }

    /** The specific discretionary reduction, against the reference, in the net's currency; null when it is known there was none. */
    private function inputReduction(SettledNetFacts $facts, BigRational $reference): ?BigRational
    {
        $input = $facts->discretionary;
        if ($input === null) {
            throw new CannotEvaluate('DISCRETIONARY_INPUT_NOT_PROVIDED');
        }
        if ($input->isNone) {
            return null;
        }
        if ($input->isPercent === true) {
            return $reference->multipliedBy($this->percent($input->value));
        }
        $amount = $this->inNetCurrency(BigRational::of($input->value), (int) $input->currencyId, $facts);

        return $input->basis === ControlBasis::PerUnit ? $amount->multipliedBy(BigRational::of($facts->quantity)) : $amount;
    }

    /** A control's amount limit in the net's currency and for the whole line. */
    private function limitAmount(NetControl $control, SettledNetFacts $facts): BigRational
    {
        $amount = $this->inNetCurrency(BigRational::of($control->value), (int) $control->currencyId, $facts);

        return $control->basis === ControlBasis::PerUnit ? $amount->multipliedBy(BigRational::of($facts->quantity)) : $amount;
    }

    /** The line's complete cost in the net's currency. Known-but-partial, unknown and absent cost are all unusable; a complete zero is real. */
    private function cost(SettledNetFacts $facts): BigRational
    {
        $cost = $facts->cost;
        if ($cost === null) {
            throw new CannotEvaluate('COST_NOT_PROVIDED');
        }
        if ($cost->certainty !== Certainty::Complete || $cost->amount === null || $cost->currencyId === null) {
            throw new CannotEvaluate($cost->certainty === Certainty::Partial ? 'COST_PARTIAL' : 'COST_UNKNOWN');
        }

        return $this->inNetCurrency(BigRational::of($cost->amount), $cost->currencyId, $facts);
    }

    /** Brings an amount into the net's currency through pinned FX evidence for this pricing date, exactly, or says why it cannot. */
    private function inNetCurrency(BigRational $amount, int $fromCurrencyId, SettledNetFacts $facts): BigRational
    {
        if ($fromCurrencyId === $facts->currencyId) {
            return $amount;
        }
        $matching = array_values(array_filter($facts->fx, static fn (FxBundleEvidence $b): bool => $b->sourceCurrencyId === $fromCurrencyId && $b->quoteCurrencyId === $facts->currencyId));
        if ($matching === []) {
            throw new CannotEvaluate('FX_EVIDENCE_MISSING');
        }
        foreach ($matching as $bundle) {
            if ($bundle->companyId !== $facts->companyId || $bundle->requested->date !== $facts->pricingDate) {
                throw new CannotEvaluate('FX_EVIDENCE_INCOMPATIBLE');
            }
        }
        if (count(array_unique(array_map(static fn (FxBundleEvidence $b): string => $b->fingerprint(), $matching))) > 1) {
            throw new CannotEvaluate('FX_EVIDENCE_AMBIGUOUS');
        }

        return $amount->multipliedBy($matching[0]->ratio());
    }

    private function percent(BigDecimal $percent): BigRational
    {
        return BigRational::of($percent)->dividedBy(100);
    }

    /** @param  array<string, BigRational>  $operands */
    private function judge(NetControl $control, bool $satisfied, string $metReason, string $violatedReason, array $operands): ControlResult
    {
        $evidence = [];
        foreach ($operands as $name => $value) {
            $simple = $value->simplified();
            $evidence[$name] = ['numerator' => (string) $simple->getNumerator(), 'denominator' => (string) $simple->getDenominator()];
        }
        if ($satisfied) {
            return new ControlResult($control, ControlOutcome::Pass, $metReason, $evidence);
        }

        return new ControlResult($control, $control->mode === ControlMode::Mandatory ? ControlOutcome::Fail : ControlOutcome::Warn, $violatedReason, $evidence);
    }
}
