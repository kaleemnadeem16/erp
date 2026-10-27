<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Support\Financial\Controls\AssemblyCostEvidenceProvider;
use App\Modules\Sales\Support\Financial\Controls\ControlRefusal;
use App\Modules\Sales\Support\Financial\Controls\EvaluateSettledNetControls;
use App\Modules\Sales\Support\Financial\Controls\NetControlKind;
use App\Modules\Sales\Support\Financial\Controls\NetControlOutcomeView;
use App\Modules\Sales\Support\Financial\Controls\NetControlSet;
use App\Modules\Sales\Support\Financial\Controls\SettledNetFacts;
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use App\Modules\Sales\Support\Fx\ResolveQuotationFx;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Pricing\PricingQuantity;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;

/**
 * The cost-blind evaluator (#29b): judges the final settled net of ONE Assembly line (one Assembly version, one work quantity) against the company's FR4 controls
 * — `FLOOR`, `MIN_MARGIN_AMOUNT`, `MIN_MARGIN_PERCENT` only — and returns nothing but `NetControlOutcomeView`: the overall and per-control PASS / WARN / FAIL /
 * UNEVALUABLE by opaque control reference, and whether issue is blocked. The cost comes from Estimating's guarded seam inside this trusted evaluation; the caller
 * needs `sales.quotation.prepare` (STAGED: refused for everyone until a reviewed holder recipe activates it) and NO estimating cost duty, and the cost, margin,
 * thresholds, FX rate and reasons never leave this class. Missing, partial or refused cost evidence makes a cost-dependent control UNEVALUABLE — never PASS, never
 * zero; `FLOOR` needs no cost and is judged regardless, and the cost is not even read when no control needs it. The control set is resolved elsewhere (R9) and passed
 * in: it is not a seller input, and any other control kind (the reductions, until their governed-reference evidence exists) is refused. No route, no write, no log.
 */
final class EvaluateAssemblyLineControls
{
    private const SUPPORTED = [NetControlKind::Floor, NetControlKind::MinMarginAmount, NetControlKind::MinMarginPercent];

    public function __construct(
        private readonly AssemblyCostEvidenceProvider $evidence,
        private readonly EvaluateSettledNetControls $evaluate,
        private readonly ResolveRoundingPolicy $rounding,
        private readonly ResolveQuotationFx $fx,
        private readonly CompanyContext $company,
    ) {}

    public function __invoke(User $actor, int $assemblyVersionId, string $workQuantity, string $finalNet, int $currencyId, string $pricingDate, NetControlSet $controls): NetControlOutcomeView
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'evaluate quotation controls');
        $context = SalesActorContext::forUser($actor, 'quotation.evaluate_assembly_controls');
        if ($assemblyVersionId < 1 || ! Currency::query()->whereKey($currencyId)->exists()) {
            throw ControlRefusal::make(ControlRefusal::FACTS_INVALID, 'The line to evaluate is not valid.', 'line');
        }
        $quantity = PricingQuantity::forAssemblyWork($workQuantity);
        FinancialDate::assertCalendarDate($pricingDate, 'pricing_date');
        foreach ($controls->controls as $control) {
            if (! in_array($control->kind, self::SUPPORTED, true)) {
                throw ControlRefusal::make(ControlRefusal::CONTROL_KIND_NOT_SUPPORTED, 'That kind of control cannot be evaluated here yet.', 'controls');
            }
        }

        $needsCost = array_filter($controls->controls, static fn ($control): bool => $control->kind->needsCost()) !== [];
        $cost = $needsCost ? $this->evidence->costFor($context, $assemblyVersionId, $quantity) : null;

        return $this->evaluate->__invoke(new SettledNetFacts(
            $this->company->id(), $currencyId, $finalNet, $quantity, $pricingDate, null, null, $cost, $this->fxFor($cost?->currencyId, $currencyId, $pricingDate), $this->rounding->__invoke($pricingDate),
        ), $controls)->consumerSafe();
    }

    /** @return list<FxBundleEvidence> the pinned evidence bringing the cost's currency into the net's, or none (the controls then say UNEVALUABLE) */
    private function fxFor(?int $costCurrencyId, int $netCurrencyId, string $pricingDate): array
    {
        if ($costCurrencyId === null || $costCurrencyId === $netCurrencyId) {
            return [];
        }
        $company = Company::query()->findOrFail($this->company->id());
        $outcome = $this->fx->attempt($costCurrencyId, $netCurrencyId, FinancialDate::explicit($pricingDate, $company->timezone, now()));

        return $outcome->evidence === null ? [] : [$outcome->evidence];
    }
}
