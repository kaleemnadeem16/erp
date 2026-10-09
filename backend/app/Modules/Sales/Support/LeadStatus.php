<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

enum LeadStatus: string
{
    case New = 'NEW';
    case Qualified = 'QUALIFIED';
    case Disqualified = 'DISQUALIFIED';
}
