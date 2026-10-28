<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/**
 * The owning module's decision on a file operation, under its own duty and scope. Registration is not permission: Files grants nothing, and a missing or throwing
 * callback denies (ADR 0153, as ADR 0137 for Documents).
 */
interface FileAccess
{
    public function authorise(FileOperationContext $context): bool;
}
