<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum InventoryCutoverStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Approved = 'APPROVED';
    case Finalised = 'FINALISED';
    case Failed = 'FAILED';
}
