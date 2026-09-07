<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

enum ImportPurpose: string
{
    case Standard = 'STANDARD';
    case InventoryCutover = 'INVENTORY_CUTOVER';
    case FinanceOpening = 'FINANCE_OPENING';
}
