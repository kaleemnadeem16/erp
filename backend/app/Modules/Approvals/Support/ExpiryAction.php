<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** ADR 0070 §9: expiry never approves or rejects. */
enum ExpiryAction: string
{
    case FlagOverdue = 'FLAG_OVERDUE';
    case ExpireRequest = 'EXPIRE_REQUEST';
}
