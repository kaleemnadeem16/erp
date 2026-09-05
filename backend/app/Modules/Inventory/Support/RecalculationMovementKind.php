<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum RecalculationMovementKind: string
{
    case Receipt = 'RECEIPT';
    case Issue = 'ISSUE';
    case Transfer = 'TRANSFER';
}
