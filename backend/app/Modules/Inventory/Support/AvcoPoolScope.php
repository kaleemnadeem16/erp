<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum AvcoPoolScope: string
{
    case CompanyItem = 'COMPANY_ITEM';
    case WarehouseItem = 'WAREHOUSE_ITEM';
    case CompanyItemBatch = 'COMPANY_ITEM_BATCH';
}
