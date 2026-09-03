<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockReviewOutcome: string
{
    case Justified = 'JUSTIFIED';
    case Flagged = 'FLAGGED';
}
