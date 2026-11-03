<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Models\Currency;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
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
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Support\CompanyContext;

/**
 * The company's published control set (D3) judged against every priced line of the revision, with the same evaluator the cost-blind seam uses. Fail-closed throughout: no set, an
 * empty set, a set with no mandatory control, a line whose net is not known, or a mandatory control that fails or cannot be evaluated (every cost-dependent control, because no cost
 * evidence exists for a manual, direct-Item or service line) blocks issue with a code and the line, never a figure. Only advisory violations pass as WARN. The fingerprint names the
 * set version and what was judged, not the limits.
 *
 * @internal Only the issue assessor calls it.
 */
final class ConfiguredQuotationControlsProvider implements QuotationControlsProvider
{
    public function __construct(private readonly EvaluateSettledNetControls $evaluate, private readonly ResolveRoundingPolicy $rounding, private readonly CompanyContext $company) {}

    /**
     * What a reduction control needs about the line: the governed reference (the settled list amount of the entered price) and the specific discretionary input the seller entered,
     * or the known absence of one. A line with no entered price has neither, so reduction controls stay UNEVALUABLE for it.
     *
     * @return array{0: GovernedReference|null, 1: DiscretionaryInput|null}
     */
    private function concession(QuotationDraftLine $line, int $currencyId, ?RoundingPolicy $policy): array
    {
        if ($line->unit_price === null || $policy === null) {
            return [null, null];
        }
        $rule = $policy->settlementRule(Currency::query()->findOrFail($currencyId));
        $list = Arithmetic::settleLine((string) $line->unit_price, (string) $line->offered_quantity, $rule)->settled;
        $discretionary = match ($line->discount_kind) {
            null => DiscretionaryInput::none(),
            'PERCENT' => DiscretionaryInput::percent((string) $line->discount_value),
            'PER_PRICED_UNIT' => DiscretionaryInput::amount((string) $line->discount_value, $currencyId, ControlBasis::PerUnit),
            default => DiscretionaryInput::amount((string) $line->discount_value, $currencyId, ControlBasis::Line),
        };

        return [new GovernedReference($list, $currencyId, 'ENTERED_PRICE'), $discretionary];
    }

    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationControlsOutcome
    {
        $stored = QuotationControlSet::query()->orderByDesc('version_no')->with('controls')->first();
        if ($stored === null) {
            return QuotationControlsOutcome::blocked([new IssueBlocker('CONTROLS_NOT_CONFIGURED', IssueBlocker::CONTROLS)]);
        }
        $set = new NetControlSet($this->company->id(), $stored->version_no, $stored->controls->map(static fn ($c): NetControl => new NetControl(
            $c->control_ref, $c->control_version, NetControlKind::from($c->kind), ControlMode::from($c->mode), (string) $c->limit_value, $c->currency_id, ControlBasis::from($c->basis),
        ))->all(), $stored->rounding_policy_fingerprint);
        if (! collect($set->controls)->contains(static fn (NetControl $c): bool => $c->mode === ControlMode::Mandatory)) {
            return QuotationControlsOutcome::blocked([new IssueBlocker('CONTROLS_NO_MANDATORY_CONTROL', IssueBlocker::CONTROLS)]);
        }

        $pricingDate = $revision->pricing_date?->format('Y-m-d');
        $policy = $pricingDate === null ? null : $this->rounding->__invoke($pricingDate);
        $lines = QuotationDraftLine::query()->where('revision_id', $revision->id)->orderBy('id')->get();
        $blockers = [];
        $judged = [];
        $worst = ControlOutcome::Pass;
        foreach ($lines as $line) {
            $net = $settledNets[$line->id] ?? null;
            if ($net === null || $pricingDate === null) {
                $blockers[] = new IssueBlocker('CONTROLS_NET_UNKNOWN', IssueBlocker::CONTROLS, $line->id);

                continue;
            }
            [$reference, $discretionary] = $this->concession($line, $revision->currency_id, $policy);
            $evaluation = ($this->evaluate)(new SettledNetFacts($this->company->id(), $revision->currency_id, $net, (string) $line->offered_quantity, $pricingDate, $reference, $discretionary, null, [], $policy), $set);
            $judged[] = [$line->id, $net, $evaluation->fingerprint()];
            foreach ($evaluation->results as $result) {
                if ($result->blocksIssue()) {
                    $blockers[] = new IssueBlocker($result->outcome === ControlOutcome::Fail ? 'CONTROL_FAILED' : 'CONTROL_UNEVALUABLE', IssueBlocker::CONTROLS, $line->id);
                }
            }
            if ($evaluation->outcome()->severity() > $worst->severity()) {
                $worst = $evaluation->outcome();
            }
        }
        if ($lines->isEmpty()) {
            $blockers[] = new IssueBlocker('CONTROLS_NO_LINES', IssueBlocker::CONTROLS);
        }
        if ($blockers !== []) {
            return QuotationControlsOutcome::blocked($blockers);
        }

        return QuotationControlsOutcome::passed($worst === ControlOutcome::Pass ? 'PASS' : 'WARN', OperationIntent::hash('quotation.controls', ['set' => $set->fingerprint(), 'lines' => $judged]));
    }
}
