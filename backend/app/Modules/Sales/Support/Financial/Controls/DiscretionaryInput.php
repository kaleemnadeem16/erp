<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;

/**
 * The specific discretionary reduction the seller entered, or the known absence of one. "Not provided" is a different thing from "none": the first is
 * PHP null in `SettledNetFacts` and makes input controls UNEVALUABLE; the second is `none()` and passes them.
 */
final readonly class DiscretionaryInput
{
    private function __construct(public bool $isNone, public ?bool $isPercent, public ?BigDecimal $value, public ?int $currencyId, public ControlBasis $basis) {}

    public static function none(): self
    {
        return new self(true, null, null, null, ControlBasis::Line);
    }

    /** A percentage of the governed reference. */
    public static function percent(string|BigDecimal $percent): self
    {
        $text = (string) $percent;
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1 || BigDecimal::of($text)->isGreaterThan(100) || BigDecimal::of($text)->strippedOfTrailingZeros()->getScale() > 4) {
            throw ControlRefusal::make(ControlRefusal::FACTS_INVALID, 'A discount percentage is 0 to 100 with at most four places.', 'discretionary');
        }

        return new self(false, true, BigDecimal::of($text), null, ControlBasis::Line);
    }

    public static function amount(string|BigDecimal $amount, int $currencyId, ControlBasis $basis = ControlBasis::Line): self
    {
        return new self(false, false, StorageAmount::of($amount, 'discretionary'), $currencyId, $basis);
    }
}
