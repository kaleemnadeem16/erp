<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** What an owner is asked to authorise: who, what, for which owner subject and purpose. Built by Files from trusted state, never from a caller-asserted verdict. */
final readonly class FileOperationContext
{
    public function __construct(
        public int $companyId,
        public ?int $actorId,
        public string $operation,
        public string $ownerTypeKey,
        public string $purposeKey,
        public string $ownerBindingIdentity,
    ) {}
}
