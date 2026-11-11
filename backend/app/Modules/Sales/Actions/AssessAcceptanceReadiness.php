<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;

/** Acceptance is valid while operational readiness is pending. No receiver is delivered in slice 2; never implies release, credit or finance clearance. */
final class AssessAcceptanceReadiness
{
    public function __construct(private readonly ReadCustomerAcceptance $read) {}

    /** @return array{acceptance_id: int, assessment_version: string, path: string, status: string, missing: list<string>} */
    public function __invoke(User $actor, int $acceptanceId, string $path = 'SERVICE_JOB'): array
    {
        // The read Action performs both guards and safe company/quotation lookup inside its transaction.
        $this->read->__invoke($actor, $acceptanceId);

        return ['acceptance_id' => $acceptanceId, 'assessment_version' => 'ACCEPTANCE_READINESS.1', 'path' => $path, 'status' => 'NOT_READY',
            'missing' => [$path === 'SERVICE_JOB' ? 'SERVICE_JOB_ADAPTER_NOT_DELIVERED' : 'HANDOVER_PATH_UNSUPPORTED']];
    }
}
