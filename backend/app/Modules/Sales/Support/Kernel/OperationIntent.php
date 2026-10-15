<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Kernel;

use App\Modules\Sales\Support\CrmOperation;

/** The canonical intent hash that distinguishes a retry from a different request — the existing CRM convention, reused rather than re-invented. */
final class OperationIntent
{
    /** @param  array<string, mixed>  $intent */
    public static function hash(string $operation, array $intent): string
    {
        return CrmOperation::hash($intent + ['operation' => $operation]);
    }
}
