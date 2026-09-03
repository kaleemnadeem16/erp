<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockMode: string
{
    case Block = 'BLOCK';
    case AuthorisedOverride = 'AUTHORISED_OVERRIDE';
}
