<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

enum PurchaseOrderFxPolicy: string
{
    case Floating = 'FLOATING';
    case Fixed = 'FIXED';
}
