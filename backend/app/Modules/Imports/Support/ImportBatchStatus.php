<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

enum ImportBatchStatus: string
{
    case Staged = 'STAGED';
    case Validated = 'VALIDATED';
    case Committed = 'COMMITTED';
    case Failed = 'FAILED';
}
