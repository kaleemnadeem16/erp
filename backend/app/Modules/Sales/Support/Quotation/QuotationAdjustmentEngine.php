<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Financial\Allocation\AllocateFinancialResiduals;
use App\Modules\Sales\Support\Financial\Allocation\AllocationShare;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Controls\ControlBasis;
use App\Modules\Sales\Support\Financial\Controls\ControlMode;
use App\Modules\Sales\Support\Financial\Controls\ControlOutcome;
use App\Modules\Sales\Support\Financial\Controls\DiscretionaryInput;
use App\Modules\Sales\Support\Financial\Controls\EvaluateSettledNetControls;
use App\Modules\Sales\Support\Financial\Controls\GovernedReference;
use App\Modules\Sales\Support\Financial\Controls\NetControl;
use App\Modules\Sales\Support\Financial\Controls\NetControlKind;
use App\Modules\Sales\Support\Financial\Controls\NetControlSet;
use App\Modules\Sales\Support\Financial\Controls\SettledNetFacts;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Pricing\Simple\CalculateSimplePrice;
use App\Modules\Sales\Support\Pricing\Simple\SimpleAdjustment;
use App\Modules\Sales\Support\Pricing\Simple\SimplePriceInput;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Exact arithmetic and approval-need for the seller's concessions on a draft (accepted R3 P5-P7, ADR 0116/0120). Everything goes through the existing kernel:
 * `CalculateSimplePrice` for a line discount (one benefit, settled once), the settlement rule for an override and a document discount, and largest-remainder allocation
 * (`AllocateFinancialResiduals`) to spread a document discount over the counted lines so the header stays the sum of its settled lines. No float, no browser arithmetic.
 *
 * Approval need is a recorded FACT, not a workflow: an override always needs approval (no risk bypass); a discount needs it unless the company's published control set holds a
 * reduction control that the discount proves to be within. No control set, no reduction control, a policy the set was not written for, or a net that cannot be computed yet is
 * "not proven within a limit" and so needs approval (default-deny). Only a stable code is returned: never a limit, a threshold or a cost.
 */
final class QuotationAdjustmentEngine
{
    public const APPROVAL_OVERRIDE = 'PRICE_OVERRIDE';

    public const APPROVAL_ABOVE_LIMIT = 'DISCOUNT_ABOVE_LIMIT';

    public const APPROVAL_NOT_CONFIGURED = 'DISCOUNT_LIMIT_NOT_CONFIGURED';

    public const APPROVAL_UNVERIFIED = 'DISCOUNT_LIMIT_UNVERIFIED';

    public function __construct(
        private readonly CalculateSimplePrice $simple,
        private readonly AllocateFinancialResiduals $allocate,
        private readonly EvaluateSettledNetControls $evaluate,
        private readonly CompanyContext $company,
    ) {}

    /**
     * One priced line: its list amount (unit price x quantity, settled), its net after a discount or an override, and what reduced it.
     *
     * @return array{list: BigDecimal, net: BigDecimal, kind: string|null, reduction: BigDecimal, discretionary: DiscretionaryInput}
     */
    public function line(QuotationDraftLine $line, int $currencyId, string $date, RoundingPolicy $policy, SettlementRule $rule, string|BigDecimal $quantity): array
    {
        $unit = (string) $line->unit_price;
        $list = Arithmetic::settleLine($unit, $quantity, $rule)->settled;
        if ($line->net_override !== null) {
            $override = BigDecimal::of((string) $line->net_override);
            if ($rule->settle(BigRational::of($override))->settled->compareTo($override) !== 0) {
                throw QuotationRefusal::make(QuotationRefusal::DISCOUNT_PRECISION_EXCEEDED, "The override must be a whole number of the currency's {$rule->quantum->decimalPlaces}-place units.", 'net_override');
            }
            if ($override->isGreaterThan($list)) {
                throw QuotationRefusal::make(QuotationRefusal::OVERRIDE_NOT_ALLOWED, 'A final-net override may only reduce the calculated net; change the unit price to raise it.', 'net_override');
            }

            return ['list' => $list, 'net' => $override, 'kind' => 'OVERRIDE', 'reduction' => $list->minus($override), 'discretionary' => DiscretionaryInput::none()];
        }
        if ($line->discount_kind === null) {
            return ['list' => $list, 'net' => $list, 'kind' => null, 'reduction' => BigDecimal::zero(), 'discretionary' => DiscretionaryInput::none()];
        }
        $input = new SimplePriceInput($this->company->id(), $currencyId, 'line:'.$line->id, 'entered:'.$line->id, $date, $unit, (string) $quantity,
            SimpleAdjustment::from((string) $line->discount_kind), (string) $line->discount_value);
        try {
            $priced = ($this->simple)($input, $policy, $rule);
        } catch (DomainInvariantException $refusal) {
            if ($refusal->reasonCode === 'SIMPLE_ADJUSTMENT_EXCEEDS_BASE') {
                throw QuotationRefusal::make(QuotationRefusal::DISCOUNT_EXCEEDS_BASE, 'The discount cannot be larger than the line.', 'discount');
            }
            throw $refusal;
        }
        $net = BigDecimal::of((string) $priced['net']);

        return ['list' => $list, 'net' => $net, 'kind' => 'DISCOUNT', 'reduction' => $list->minus($net), 'discretionary' => $input->discretionary];
    }

    /**
     * The document discount over the counted lines: its settled amount and each line's share. `$nets` is line id => net after line concessions (all known).
     *
     * @param  array<int, BigDecimal>  $nets
     * @return array{amount: BigDecimal, shares: array<int, BigDecimal>}
     */
    public function document(array $nets, string $kind, string $value, SettlementRule $rule): array
    {
        $sum = BigDecimal::zero();
        foreach ($nets as $net) {
            $sum = $sum->plus($net);
        }
        if ($kind === 'PERCENT') {
            $amount = $rule->settle(BigRational::of($sum)->multipliedBy(BigRational::of($value))->dividedBy(100))->settled;
        } else {
            $amount = BigDecimal::of($value);
            if ($rule->settle(BigRational::of($amount))->settled->compareTo($amount) !== 0) {
                throw QuotationRefusal::make(QuotationRefusal::DISCOUNT_PRECISION_EXCEEDED, "The document discount must be a whole number of the currency's {$rule->quantum->decimalPlaces}-place units.", 'document_discount');
            }
        }
        if ($amount->isGreaterThan($sum)) {
            throw QuotationRefusal::make(QuotationRefusal::DISCOUNT_EXCEEDS_BASE, 'The document discount cannot be larger than the quotation.', 'document_discount');
        }
        $shares = [];
        if ($amount->isZero() || $nets === []) {
            foreach ($nets as $id => $net) {
                $shares[$id] = BigDecimal::zero()->toScale(4);
            }

            return ['amount' => $amount, 'shares' => $shares];
        }
        $components = [];
        foreach ($nets as $id => $net) {
            $components[] = AllocationShare::eligible((string) $id, $net, $net);
        }
        $allocation = ($this->allocate)($amount, $components, $rule);
        foreach ($nets as $id => $net) {
            $shares[$id] = $allocation->for((string) $id)->allocated;
        }

        return ['amount' => $amount, 'shares' => $shares];
    }

    /**
     * Whether a concession needs approval, as a stable code or null. `$net` is null while the figures cannot be computed (then a discount is "unverified").
     *
     * @param  array{list: BigDecimal, net: BigDecimal, discretionary: DiscretionaryInput}|null  $figures
     */
    public function approvalFor(bool $override, bool $discount, ?array $figures, int $currencyId, ?string $date, ?RoundingPolicy $policy, string|BigDecimal $quantity): ?string
    {
        if ($override) {
            return self::APPROVAL_OVERRIDE;
        }
        if (! $discount) {
            return null;
        }
        $set = $this->reductionControls();
        if ($set === null) {
            return self::APPROVAL_NOT_CONFIGURED;
        }
        if ($figures === null || $date === null || $policy === null) {
            return self::APPROVAL_UNVERIFIED;
        }
        $facts = new SettledNetFacts($this->company->id(), $currencyId, $figures['net'], $quantity, $date, new GovernedReference($figures['list'], $currencyId, 'ENTERED_PRICE'),
            $figures['discretionary'], null, [], $policy);

        return $this->verdict($set, $facts);
    }

    /** The document discount judged as one input against the counted lines' net before it. */
    public function approvalForDocument(BigDecimal $before, BigDecimal $after, string $kind, string $value, int $currencyId, ?string $date, ?RoundingPolicy $policy): ?string
    {
        $set = $this->reductionControls();
        if ($set === null) {
            return self::APPROVAL_NOT_CONFIGURED;
        }
        if ($date === null || $policy === null) {
            return self::APPROVAL_UNVERIFIED;
        }
        $input = $kind === 'PERCENT' ? DiscretionaryInput::percent($value) : DiscretionaryInput::amount($value, $currencyId, ControlBasis::Line);
        $facts = new SettledNetFacts($this->company->id(), $currencyId, $after, '1', $date, new GovernedReference($before, $currencyId, 'COUNTED_LINES_NET'), $input, null, [], $policy);

        return $this->verdict($set, $facts);
    }

    private function verdict(NetControlSet $set, SettledNetFacts $facts): ?string
    {
        $worst = ControlOutcome::Pass;
        foreach (($this->evaluate)($facts, $set)->results as $result) {
            if ($result->outcome->severity() > $worst->severity()) {
                $worst = $result->outcome;
            }
        }

        return match ($worst) {
            ControlOutcome::Pass => null,
            ControlOutcome::Unevaluable => self::APPROVAL_UNVERIFIED,
            default => self::APPROVAL_ABOVE_LIMIT,
        };
    }

    /** The reduction controls of the latest published set (the only ones a discount can be proven against), or null when there are none. */
    private function reductionControls(): ?NetControlSet
    {
        $stored = QuotationControlSet::query()->orderByDesc('version_no')->with('controls')->first();
        if ($stored === null) {
            return null;
        }
        $controls = $stored->controls->map(static fn ($c): NetControl => new NetControl(
            $c->control_ref, $c->control_version, NetControlKind::from($c->kind), ControlMode::from($c->mode), (string) $c->limit_value, $c->currency_id, ControlBasis::from($c->basis),
        ))->filter(static fn (NetControl $c): bool => $c->kind->needsReference())->values()->all();

        return $controls === [] ? null : new NetControlSet($this->company->id(), $stored->version_no, $controls, $stored->rounding_policy_fingerprint);
    }
}
