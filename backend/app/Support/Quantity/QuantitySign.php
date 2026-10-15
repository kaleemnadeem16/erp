<?php

declare(strict_types=1);

namespace App\Support\Quantity;

/** What a destination permits: a receipt line is Positive, a book balance may be NonNegative, a signed adjustment is Any. */
enum QuantitySign: string
{
    case Positive = 'POSITIVE';
    case NonNegative = 'NON_NEGATIVE';
    case Any = 'ANY';
}
