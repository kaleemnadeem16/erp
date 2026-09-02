<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum LocationType: string
{
    case Storage = 'STORAGE';
    case Receiving = 'RECEIVING';
    case Quarantine = 'QUARANTINE';
    case Dispatch = 'DISPATCH';
    case Returns = 'RETURNS';
    case Transit = 'TRANSIT';
    case Scrap = 'SCRAP';
    case Production = 'PRODUCTION';
    case View = 'VIEW';
}
