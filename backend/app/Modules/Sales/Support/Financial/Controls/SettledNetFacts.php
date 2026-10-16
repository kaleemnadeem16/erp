<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxBundleEvidence;
use App\Modules\Sales\Support\Pricing\PricingResult;
use Brick\Math\BigDecimal;

/**
 * Everything the evaluator may consult about one settled line, assembled server-side. Cost is the line's cost for the priced quantity as honest certainty;
 * FX is the pinned evidence already resolved for the pricing date; the rounding policy is the one the net was settled under. What is absent is absent:
 * a missing cost, reference, discretionary input or FX bundle is PHP null / an empty list, and the controls that need it are UNEVALUABLE.
 *
 * Internal and server-side: it holds protected operands and must never be serialised to a reader (use `NetControlEvaluation::consumerSafe`).
 */
final readonly class SettledNetFacts
{
    public BigDecimal $finalNet;

    public BigDecimal $quantity;

    /** @param  list<mixed>  $fx FxBundleEvidence values; anything else is refused */
    public function __construct(
        public int $companyId,
        public int $currencyId,
        string|BigDecimal $finalNet,
        string|BigDecimal $quantity,
        public string $pricingDate,
        public ?GovernedReference $reference = null,
        public ?DiscretionaryInput $discretionary = null,
        public ?FinancialResult $cost = null,
        public array $fx = [],
        public ?RoundingPolicy $roundingPolicy = null,
    ) {
        FinancialDate::assertCalendarDate($pricingDate, 'pricing_date');
        $this->finalNet = StorageAmount::of($finalNet, 'final_net');
        $text = (string) $quantity;
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1 || ! BigDecimal::of($text)->isPositive() || BigDecimal::of($text)->strippedOfTrailingZeros()->getScale() > 9) {
            throw ControlRefusal::make(ControlRefusal::FACTS_INVALID, 'The priced quantity must be a positive plain decimal of at most nine places.', 'quantity');
        }
        $this->quantity = BigDecimal::of($text);
        foreach ($fx as $bundle) {
            if (! $bundle instanceof FxBundleEvidence) {
                throw ControlRefusal::make(ControlRefusal::FACTS_INVALID, 'FX evidence must be FxBundleEvidence values.', 'fx');
            }
        }
    }

    /**
     * Facts from the pricing kernel's result: the settled line, the exact proven quantity and the cost read for it. A result whose cost was withheld from a
     * reader carries no cost, so it gives UNKNOWN cost here — an evaluator is never fed cost it was not entitled to.
     *
     * @param  list<FxBundleEvidence>  $fx
     */
    public static function fromPricing(PricingResult $priced, int $companyId, string $pricingDate, ?GovernedReference $reference = null, ?DiscretionaryInput $discretionary = null, array $fx = [], ?RoundingPolicy $roundingPolicy = null): self
    {
        $cost = $priced->costWithheld ? null : $priced->cost;

        return new self($companyId, $priced->line->rule->currencyId, $priced->line->settled, $priced->quantity, $pricingDate, $reference, $discretionary, $cost, $fx, $roundingPolicy);
    }
}
