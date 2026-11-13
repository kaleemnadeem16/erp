<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;

/**
 * The Sales-owned port the Jobs module implements to receive a released service. It is bound by Jobs (not here): while it is unbound every release refuses (fail closed).
 *
 * `receive` is called INSIDE the release transaction, after the release event row exists, on the same connection and session. An implementation must call
 * `$release->assertCurrent()`, independently require its own `job.receive`, bind the Job origin to `$release->releaseEventId` by company-composite key, create exactly one Job and
 * return safe references. Any exception rolls the whole release back. `referenceFor` answers, for an existing release event, the Job it produced (null if none).
 *
 * `assertMayReceive` is the receiver's own independent authority check and is REQUIRED of every implementation (review P1): the coordinator calls it at the actual decision
 * point of EVERY attempt — fresh release, exact-key replay and business (stage/purpose) replay alike — inside the locked transaction, after the access fence and the source
 * locks are taken and before any stored result is returned or anything is written, so a revoked duty or a suspended member never gets a Job reference back. It must read the
 * actor's CURRENT state (never a cached user), throw an AuthorizationException when not permitted, take no Job lock and write nothing.
 */
interface ReleasedServiceReceiver
{
    public function assertMayReceive(User $actor): void;

    public function receive(VerifiedServiceRelease $release): ReleasedServiceReference;

    public function referenceFor(int $releaseEventId): ?ReleasedServiceReference;
}
