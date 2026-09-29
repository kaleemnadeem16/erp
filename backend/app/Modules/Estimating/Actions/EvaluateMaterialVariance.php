<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\MaterialVariancePolicyBand;
use App\Modules\Estimating\Support\MaterialVarianceEvaluation;
use App\Modules\Estimating\Support\MaterialVariancePolicyResolution;
use App\Modules\Estimating\Support\VarianceDirection;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceMeasure;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * ADR 0054's deterministic evaluation of one planned quantity against its standard. It never
 * writes anything and never rounds an intermediate value: percentages carry six decimals.
 *
 *  - planned == standard: no adjustment, no effect.
 *  - No policy resolved: ADR 0054's built-in default — any non-zero variance needs a reason. There
 *    is no invented allowance.
 *  - A policy: every band in the difference's direction is measured; a band matches when the
 *    measured value is at or beyond its threshold; the strongest matching effect wins. A non-zero
 *    difference that matches no band is recorded (AUDIT_ONLY). The policy's `minimum_effect` is a
 *    floor under that.
 *
 * A band that cannot be measured is skipped and reported, never treated as passing or as zero: a
 * percentage of a zero standard, a cost without cost evidence, a quantity band whose unit does not
 * convert for this item. `APPROVAL_REQUIRED` resolves to BLOCKED with a stated limitation, because
 * ADR 0054 §7 forbids degrading it to a reason before the shared approval workflow exists.
 *
 * Standard and planned are in the item's stock unit. `unitCost` is the internal cost per stock
 * unit, or null when there is no defensible evidence.
 */
final class EvaluateMaterialVariance
{
    private const SCALE = 6;

    public function __construct(private readonly ItemUomConversionService $conversion) {}

    public function __invoke(
        Item $item,
        BigDecimal $standard,
        BigDecimal $planned,
        MaterialVariancePolicyResolution $resolution,
        ?BigDecimal $unitCost,
    ): MaterialVarianceEvaluation {
        $delta = $planned->minus($standard);
        $limitations = [];
        $percent = null;

        if ($standard->isGreaterThan(0)) {
            $percent = $delta->multipliedBy(100)->dividedBy($standard, self::SCALE, RoundingMode::HALF_UP);
        } elseif (! $delta->isZero()) {
            $limitations[] = 'The standard quantity is zero, so no percentage can be calculated; only absolute and risk rules apply.';
        }

        if ($delta->isZero()) {
            return $this->result($standard, $planned, $delta, $percent, null, null, $resolution, [], [], $limitations);
        }

        $direction = $delta->isPositive() ? VarianceDirection::Over : VarianceDirection::Under;

        if ($resolution->policy === null) {
            return $this->result($standard, $planned, $delta, $percent, $direction, VarianceEffect::ReasonRequired, $resolution, [[
                'source' => 'DEFAULT', 'band_id' => null, 'direction' => $direction->value, 'measure' => null,
                'threshold' => null, 'unit' => null, 'observed' => null, 'effect' => VarianceEffect::ReasonRequired->value,
            ]], [], [...$limitations, 'No variance policy is configured, so any planned difference needs a reason.']);
        }

        $matched = [];
        $skipped = [];
        $effect = VarianceEffect::AuditOnly;

        foreach ($resolution->policy->bands as $band) {
            if ($band->direction !== $direction) {
                continue;
            }

            [$observed, $unit, $why] = $this->measure($band, $item, $standard, $delta, $percent, $unitCost);
            if ($observed === null) {
                $skipped[] = ['band_id' => $band->id, 'reason' => (string) $why];

                continue;
            }
            if ($observed->isLessThan(BigDecimal::of($band->threshold))) {
                continue;
            }

            $matched[] = [
                'source' => 'BAND', 'band_id' => $band->id, 'direction' => $direction->value, 'measure' => $band->measure->value,
                'threshold' => (string) BigDecimal::of($band->threshold)->stripTrailingZeros(), 'unit' => $unit,
                'observed' => (string) $observed->stripTrailingZeros(), 'effect' => $band->effect->value,
            ];
            if ($band->effect->strongerThan($effect)) {
                $effect = $band->effect;
            }
        }

        $minimum = $resolution->policy->minimum_effect;
        if ($minimum !== null && $minimum->strongerThan($effect)) {
            $effect = $minimum;
            $matched[] = [
                'source' => 'MINIMUM', 'band_id' => null, 'direction' => $direction->value, 'measure' => null,
                'threshold' => null, 'unit' => null, 'observed' => null, 'effect' => $minimum->value,
            ];
        }

        if ($effect === VarianceEffect::ApprovalRequired) {
            $effect = VarianceEffect::Blocked;
            $limitations[] = 'This difference falls in an approval-required band, but the shared approval workflow is not available yet, so it is blocked rather than downgraded.';
        }

        return $this->result($standard, $planned, $delta, $percent, $direction, $effect, $resolution, $matched, $skipped, $limitations);
    }

    /**
     * @return array{0: BigDecimal|null, 1: string|null, 2: string|null} observed value, its unit label, why it could not be measured
     */
    private function measure(MaterialVariancePolicyBand $band, Item $item, BigDecimal $standard, BigDecimal $delta, ?BigDecimal $percent, ?BigDecimal $unitCost): array
    {
        $absolute = $delta->abs();

        switch ($band->measure) {
            case VarianceMeasure::Quantity:
                try {
                    // 1 band-unit = factor × the item's stock unit, so the stock-unit difference divides by it.
                    $factor = $this->conversion->stockUnitFactor($item, $band->uom);
                } catch (InvalidArgumentException) {
                    return [null, null, "Its unit ({$band->uom->code}) does not convert to {$item->code}'s stock unit."];
                }

                return [$absolute->dividedBy($factor, self::SCALE, RoundingMode::HALF_UP), $band->uom->code, null];

            case VarianceMeasure::Percent:
                return $percent === null
                    ? [null, null, 'The standard quantity is zero, so a percentage is undefined.']
                    : [$percent->abs(), '%', null];

            case VarianceMeasure::CostAmount:
                return $unitCost === null
                    ? [null, null, 'There is no internal cost evidence for this item.']
                    : [$absolute->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP), null, null];

            case VarianceMeasure::CostPercent:
                if ($unitCost === null) {
                    return [null, null, 'There is no internal cost evidence for this item.'];
                }
                $standardCost = $standard->multipliedBy($unitCost);

                return $standardCost->isGreaterThan(0)
                    ? [$absolute->multipliedBy($unitCost)->multipliedBy(100)->dividedBy($standardCost, self::SCALE, RoundingMode::HALF_UP), '%', null]
                    : [null, null, 'The standard cost is zero, so a cost percentage is undefined.'];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $matched
     * @param  list<array{band_id: int, reason: string}>  $skipped
     * @param  list<string>  $limitations
     */
    private function result(
        BigDecimal $standard,
        BigDecimal $planned,
        BigDecimal $delta,
        ?BigDecimal $percent,
        ?VarianceDirection $direction,
        ?VarianceEffect $effect,
        MaterialVariancePolicyResolution $resolution,
        array $matched,
        array $skipped,
        array $limitations,
    ): MaterialVarianceEvaluation {
        /** @var list<array{source: string, band_id: int|null, direction: string|null, measure: string|null, threshold: string|null, unit: string|null, observed: string|null, effect: string}> $matched */
        return new MaterialVarianceEvaluation(
            $standard, $planned, $delta, $percent, $direction, $effect, $resolution->method, $resolution->policy?->id,
            $matched, $skipped, $limitations,
        );
    }
}
