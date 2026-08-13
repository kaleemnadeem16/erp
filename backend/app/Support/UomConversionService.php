<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Uom;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Converts a quantity between two units of measure. Conversion is only valid within
 * the same dimension — crossing dimensions (e.g. litres to square metres) is an
 * application rate, not a UoM concern. See docs/domain/units-of-measure.md.
 */
class UomConversionService
{
    public function convert(BigDecimal|int|string $quantity, Uom $from, Uom $to): BigDecimal
    {
        if ($from->dimension !== $to->dimension) {
            throw new InvalidArgumentException(
                "Cannot convert [{$from->code}] ({$from->dimension->value}) to ".
                "[{$to->code}] ({$to->dimension->value}) — different dimensions."
            );
        }

        return BigDecimal::of($quantity)
            ->multipliedBy($from->factor_to_base)
            ->dividedBy($to->factor_to_base, 9, RoundingMode::HALF_UP);
    }
}
