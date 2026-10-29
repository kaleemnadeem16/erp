<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** What a malware scan concluded. NOT_CONFIGURED is a visible, recorded fact, never a pass. */
enum ScanResult: string
{
    case NotConfigured = 'NOT_CONFIGURED';
    case Pending = 'PENDING';
    case Clean = 'CLEAN';
    case Infected = 'INFECTED';
    case Error = 'ERROR';
}
