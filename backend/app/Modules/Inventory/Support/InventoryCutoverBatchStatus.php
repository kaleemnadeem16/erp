<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum InventoryCutoverBatchStatus: string
{
    case Staged = 'STAGED';
    case Validated = 'VALIDATED';
    case Committed = 'COMMITTED';
    case Failed = 'FAILED';
}
