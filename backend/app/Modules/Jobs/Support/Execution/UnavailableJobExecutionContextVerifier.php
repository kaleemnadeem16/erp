<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Execution;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;

/** The production binding until a real, reviewed source integration exists: it proves nothing, so every NEW lifecycle command refuses. A valid replay never reaches it. */
final class UnavailableJobExecutionContextVerifier implements JobExecutionContextVerifier
{
    public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
    {
        throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'No reviewed source integration exists for this Job yet, so this change cannot be made.', 'job_id');
    }
}
