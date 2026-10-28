<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Execution;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;

/**
 * Port to the owner of a Job's source (Sales release, canonical fulfilment, Inventory and Purchasing readiness). `enter` is called INSIDE the command's transaction on
 * the runtime connection, BEFORE the Job row is locked: it takes the source-side fences first and must never call back into a source lock once the Job is held. It
 * proves current safe record eligibility and, for fresh work, readiness; when it cannot prove either it throws a refusal — a missing adapter, a fixture origin or an
 * unproven exclusion is never success and never a "no impact" stub. The only binding delivered is UnavailableJobExecutionContextVerifier.
 */
interface JobExecutionContextVerifier
{
    public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext;
}
