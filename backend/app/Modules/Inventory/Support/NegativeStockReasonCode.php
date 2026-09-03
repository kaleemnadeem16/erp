<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockReasonCode: string
{
    case LateReceipt = 'LATE_RECEIPT';
    case PaperworkDelay = 'PAPERWORK_DELAY';
    case OperationalEmergency = 'OPERATIONAL_EMERGENCY';
    case Other = 'OTHER';
}
