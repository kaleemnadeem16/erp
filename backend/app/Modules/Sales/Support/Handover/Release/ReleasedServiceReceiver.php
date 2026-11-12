<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/**
 * The Sales-owned port the Jobs module implements to receive a released service. It is bound by Jobs (not here): while it is unbound every release refuses (fail closed).
 *
 * `receive` is called INSIDE the release transaction, after the release event row exists, on the same connection and session. An implementation must call
 * `$release->assertCurrent()`, independently require its own `job.receive`, bind the Job origin to `$release->releaseEventId` by company-composite key, create exactly one Job and
 * return safe references. Any exception rolls the whole release back. `referenceFor` answers, for an existing release event, the Job it produced (null if none).
 */
interface ReleasedServiceReceiver
{
    public function receive(VerifiedServiceRelease $release): ReleasedServiceReference;

    public function referenceFor(int $releaseEventId): ?ReleasedServiceReference;
}
