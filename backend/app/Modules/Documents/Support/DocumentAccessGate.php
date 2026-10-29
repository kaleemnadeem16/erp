<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the owning module whether an operation may proceed and fails closed: only an explicit `true` allows it, a denial or a throwing callback refuses. Each
 * refusal is logged as one structured, non-sensitive line — type key, operation, company, actor, outcome and, for a throw, the exception class — and never the
 * projection, the owner identity or the exception message, which may carry the owner's content.
 */
final class DocumentAccessGate
{
    public static function allows(DocumentTypeAdapter $adapter, DocumentOperationContext $context): bool
    {
        try {
            $allowed = $adapter->access()->authorise($context) === true;
            $outcome = $allowed ? 'allowed' : 'denied';
            $class = null;
        } catch (Throwable $e) {
            $allowed = false;
            $outcome = 'threw';
            $class = $e::class;
        }
        if (! $allowed) {
            Log::warning('documents.access_refused', array_filter([
                'type_key' => $context->typeKey, 'operation' => $context->operation, 'company_id' => $context->companyId, 'actor_id' => $context->actorId,
                'outcome' => $outcome, 'exception' => $class,
            ], static fn (mixed $value): bool => $value !== null));
        }

        return $allowed;
    }
}
