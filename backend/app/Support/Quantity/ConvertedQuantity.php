<?php

declare(strict_types=1);

namespace App\Support\Quantity;

use Brick\Math\BigDecimal;

/**
 * An exact conversion: the quantity in the source unit, the factor used (as evidence) and the result at the destination scale. `exact` is the unrounded
 * product and always equals `result`, because a conversion that is not exact is refused, never stored as an approximation.
 */
final readonly class ConvertedQuantity
{
    public function __construct(
        public BigDecimal $source,
        public BigDecimal $factor,
        public BigDecimal $exact,
        public BigDecimal $result,
    ) {}
}
