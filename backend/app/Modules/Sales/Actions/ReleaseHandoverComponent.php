<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\Handover\Release\ReleaseCoordinator;
use App\Modules\Sales\Support\Handover\Release\ReleaseIntent;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Releases one prepared SERVICE_JOB stage: immutable release evidence and exactly one numbered Job, atomically. DISABLED: the duty is STAGED, there is no route, and with
 * no Jobs receiver bound it refuses. Replay by the same actor and key returns the stored result; a different key or actor resolves to the same single release or is refused.
 */
final class ReleaseHandoverComponent
{
    public function __construct(private readonly ReleaseCoordinator $coordinator) {}

    /** @return array{release_event_id:int,case_id:int,job_id:int,job_code:string,replayed:bool} */
    public function __invoke(User $actor, ?string $requestKey, int $stageId, int $basisId, string $readinessFingerprint): array
    {
        // The exact release duty is judged here as well as in the coordinator's locked decision (the coordinator remains the owner of every other rule).
        SalesAccess::require($actor, P::HANDOVER_RELEASE, 'release a handover');

        return $this->coordinator->release($actor, $requestKey, new ReleaseIntent($stageId, $basisId, $readinessFingerprint));
    }
}
