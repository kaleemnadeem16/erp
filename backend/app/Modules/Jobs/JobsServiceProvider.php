<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Execution\UnavailableJobExecutionContextVerifier;
use Illuminate\Support\ServiceProvider;

/**
 * The disabled Jobs foundation (SP0b, J1-F1) and its non-operative access foundation (J-AU0). It binds the duty manifest and the fail-closed source verifier only: no route, permission vocabulary,
 * registry hook, duty registration or entry point that creates or changes a Job. Later slices add the receiver, lifecycle Actions and their guards here.
 */
class JobsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JobDutyManifest::class, fn (): JobDutyManifest => JobDutyManifest::shipped());
        // Fail closed: no reviewed source integration exists, so a NEW lifecycle command is refused until one is bound here by its owner.
        $this->app->bindIf(JobExecutionContextVerifier::class, UnavailableJobExecutionContextVerifier::class);
    }
}
