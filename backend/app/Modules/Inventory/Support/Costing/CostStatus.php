<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

enum CostStatus: string
{
    case Costed = 'COSTED';
    case Partial = 'PARTIAL';
    case Unknown = 'UNKNOWN';
}
