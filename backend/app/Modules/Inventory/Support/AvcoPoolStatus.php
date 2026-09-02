<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum AvcoPoolStatus: string
{
    case Active = 'ACTIVE';
    case Frozen = 'FROZEN';
}
