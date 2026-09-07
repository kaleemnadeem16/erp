<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum InventoryCutoverMode: string
{
    case OpeningState = 'OPENING_STATE';
    case Operational = 'OPERATIONAL';
    case FullHistory = 'FULL_HISTORY';
}
