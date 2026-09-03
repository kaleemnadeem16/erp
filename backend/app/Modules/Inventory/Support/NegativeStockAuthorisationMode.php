<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockAuthorisationMode: string
{
    case PriorApproval = 'PRIOR_APPROVAL';
    case ImmediateWithReview = 'IMMEDIATE_WITH_REVIEW';
}
