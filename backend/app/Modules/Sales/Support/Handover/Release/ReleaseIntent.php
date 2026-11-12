<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Sales\Support\Kernel\OperationIntent;

/** What the releaser reviewed: the stage, the exact completion basis and the readiness fingerprint they saw. Nothing else is client-supplied. */
final readonly class ReleaseIntent
{
    public function __construct(public int $stageId, public int $basisId, public string $readinessFingerprint) {}

    /** The business identity of the release: independent of key, actor and review state, so no other request can release the same stage and purpose differently. */
    public function businessHash(): string
    {
        return OperationIntent::hash('handover.release.business', ['stage_id' => $this->stageId, 'basis_id' => $this->basisId]);
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return ['stage_id' => $this->stageId, 'basis_id' => $this->basisId, 'fingerprint' => $this->readinessFingerprint];
    }
}
