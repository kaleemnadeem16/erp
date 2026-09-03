<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockApprovalDecision: string
{
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
