<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

enum TaxSupport: string
{
    case Supported = 'SUPPORTED';
    case Unsupported = 'UNSUPPORTED';
    case Unknown = 'UNKNOWN';
}
