<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum InventoryCutoverNegativeOpeningStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
}
