<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

/**
 * The supported final-net checks. Percentage and absolute limits are separate kinds and are never converted into one another by the evaluator's
 * caller: each is a control of its own, so a company can hold both and each is judged independently.
 */
enum NetControlKind: string
{
    /** The final settled net must be at least this amount. */
    case Floor = 'FLOOR';

    /** Final net minus complete cost must be at least this amount. */
    case MinMarginAmount = 'MIN_MARGIN_AMOUNT';

    /** (Final net − complete cost) ÷ final net must be at least this percentage. Undefined at zero net. */
    case MinMarginPercent = 'MIN_MARGIN_PERCENT';

    /** The specific discretionary input, expressed against the governed reference, may be at most this percentage of the reference. */
    case InputReductionPercent = 'INPUT_REDUCTION_PERCENT';

    case InputReductionAmount = 'INPUT_REDUCTION_AMOUNT';

    /** The aggregate reduction (reference − final net) may be at most this percentage of the governed reference. */
    case AggregateReductionPercent = 'AGGREGATE_REDUCTION_PERCENT';

    case AggregateReductionAmount = 'AGGREGATE_REDUCTION_AMOUNT';

    public function isPercent(): bool
    {
        return in_array($this, [self::MinMarginPercent, self::InputReductionPercent, self::AggregateReductionPercent], true);
    }

    public function needsCost(): bool
    {
        return in_array($this, [self::MinMarginAmount, self::MinMarginPercent], true);
    }

    public function needsReference(): bool
    {
        return in_array($this, [self::InputReductionPercent, self::InputReductionAmount, self::AggregateReductionPercent, self::AggregateReductionAmount], true);
    }
}
