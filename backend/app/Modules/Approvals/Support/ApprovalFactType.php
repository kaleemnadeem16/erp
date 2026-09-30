<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** The kind of value a subject's routing fact holds; it fixes which operators make sense. */
enum ApprovalFactType: string
{
    case Decimal = 'DECIMAL';
    case String = 'STRING';
    case Id = 'ID';
}
