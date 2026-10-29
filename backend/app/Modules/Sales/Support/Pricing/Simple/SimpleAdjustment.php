<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Simple;

enum SimpleAdjustment: string
{
    case None = 'NONE';
    case Percent = 'PERCENT';
    case PerUnit = 'PER_PRICED_UNIT';
    case PerLine = 'PER_LINE';
}
