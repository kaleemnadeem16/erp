<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum RecalculationApprovalDecision: string
{
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
