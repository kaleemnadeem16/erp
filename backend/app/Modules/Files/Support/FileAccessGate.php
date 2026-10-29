<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the owning module whether a file operation may proceed and fails closed: only an explicit `true` allows it, a denial or a throwing callback refuses. Each
 * refusal is logged as one structured, non-sensitive line — owner type, purpose, operation, company, actor, outcome and, for a throw, the exception class — and never
 * the owner identity, a file name or the exception message.
 */
final class FileAccessGate
{
    public static function allows(FileOwnerAdapter $adapter, FileOperationContext $context): bool
    {
        $class = null;
        try {
            $allowed = $adapter->access()->authorise($context) === true;
            $outcome = $allowed ? 'allowed' : 'denied';
        } catch (Throwable $e) {
            $allowed = false;
            $outcome = 'threw';
            $class = $e::class;
        }
        if (! $allowed) {
            Log::warning('files.access_refused', array_filter([
                'owner_type_key' => $context->ownerTypeKey, 'purpose_key' => $context->purposeKey, 'operation' => $context->operation, 'company_id' => $context->companyId,
                'actor_id' => $context->actorId, 'outcome' => $outcome, 'exception' => $class,
            ], static fn (mixed $value): bool => $value !== null));
        }

        return $allowed;
    }
}
