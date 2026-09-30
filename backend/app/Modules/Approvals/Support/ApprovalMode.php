<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** ADR 0070 §2: how a subject type is treated. A missing policy is never read as approved. */
enum ApprovalMode: string
{
    case None = 'NONE';
    case Policy = 'POLICY';
    case Required = 'REQUIRED';
}
