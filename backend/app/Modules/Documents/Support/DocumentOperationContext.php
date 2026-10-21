<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * What an adapter is asked to authorise: who (an actor, or a service with none), what, for which subject and audience. Built by Documents from trusted
 * state, never from a caller-asserted verdict.
 */
final readonly class DocumentOperationContext
{
    public function __construct(
        public int $companyId,
        public ?int $actorId,
        public string $operation,
        public string $typeKey,
        public string $audience,
        public string $ownerBindingIdentity,
    ) {}
}
