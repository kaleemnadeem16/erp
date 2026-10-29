<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * What an adapter is asked to authorise: who (an actor, or a service with none), what, for which subject and audience. Built by Documents from trusted
 * state, never from a caller-asserted verdict.
 *
 * For a freeze (`bind_manifest`, or `bind_manifest_original` for an ORIGINAL) it also carries what the owner needs to decide whether THIS document may be frozen
 * for THIS purpose: the purpose, the source generation, the source snapshot reference and the stable generation identity. Documents infers no business state
 * (issued, accepted, ...) from them; admissibility is the owner's decision, so an adapter that has not decided to allow originals simply denies the operation.
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
        public ?string $purpose = null,
        public ?int $sourceGeneration = null,
        public ?string $sourceSnapshotReference = null,
        public ?string $stableGenerationIdentity = null,
    ) {}
}
