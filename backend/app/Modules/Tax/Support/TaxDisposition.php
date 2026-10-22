<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

enum TaxDisposition: string
{
    case Taxable = 'TAXABLE';
    case ZeroRated = 'ZERO_RATED';
    case Exempt = 'EXEMPT';
    case NotApplicable = 'NOT_APPLICABLE';
    case Unknown = 'UNKNOWN';
}
