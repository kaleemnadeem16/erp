<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

enum TaxNature: string
{
    case Goods = 'GOODS';
    case Services = 'SERVICES';
    case Both = 'BOTH';
}
