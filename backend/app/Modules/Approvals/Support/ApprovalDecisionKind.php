<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

enum ApprovalDecisionKind: string
{
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
