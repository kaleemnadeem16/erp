<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum InventoryLocationMode: string
{
    case Simple = 'SIMPLE';
    case Structured = 'STRUCTURED';
}
