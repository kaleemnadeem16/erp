<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum WarehouseLocationRole: string
{
    case Receiving = 'RECEIVING';
    case Quarantine = 'QUARANTINE';
    case Dispatch = 'DISPATCH';
    case Returns = 'RETURNS';
    case Transit = 'TRANSIT';
    case Production = 'PRODUCTION';
}
