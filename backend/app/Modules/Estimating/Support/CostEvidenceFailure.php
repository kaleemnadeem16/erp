<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use RuntimeException;

/**
 * An unexpected failure while reading cost evidence. The message is fixed and no previous exception is chained, so nothing that was being costed (an item, a rate, an
 * amount, a query) can reach a log line or an error response through it.
 */
final class CostEvidenceFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cost evidence could not be read.');
    }
}
