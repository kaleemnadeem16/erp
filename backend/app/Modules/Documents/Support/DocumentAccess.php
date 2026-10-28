<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The owning module's decision on a document operation, under its own duty and scope. Registration is not permission: Documents grants nothing and a
 * missing or throwing callback denies (ADR 0137).
 */
interface DocumentAccess
{
    public function authorise(DocumentOperationContext $context): bool;
}
