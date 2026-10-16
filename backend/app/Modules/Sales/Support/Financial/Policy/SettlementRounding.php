<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

use Brick\Math\RoundingMode;

/**
 * The rounding modes a Sales policy may name for settling a line net to the currency's minor unit. Amounts are never negative, so CEILING and FLOOR would
 * only duplicate UP and DOWN, and UNNECESSARY is a refusal rather than a rule; none of those is offered. The default is HALF_UP (ADR 0120).
 */
enum SettlementRounding: string
{
    case HalfUp = 'HALF_UP';
    case HalfEven = 'HALF_EVEN';
    case HalfDown = 'HALF_DOWN';
    case Up = 'UP';
    case Down = 'DOWN';

    public function mode(): RoundingMode
    {
        return match ($this) {
            self::HalfUp => RoundingMode::HALF_UP,
            self::HalfEven => RoundingMode::HALF_EVEN,
            self::HalfDown => RoundingMode::HALF_DOWN,
            self::Up => RoundingMode::UP,
            self::Down => RoundingMode::DOWN,
        };
    }
}
