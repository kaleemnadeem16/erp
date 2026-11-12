<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Modules\Jobs\Actions\ReceiveReleasedService;
use App\Modules\Jobs\Numbering\JobNumbering;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\Release\SalesJobOrigins;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Sales\Support\Handover\Release\JobSalesOrigin;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\SalesJobExecutionContextVerifier;
use App\Support\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * The disabled Jobs foundation (SP0b, J1-F1), its non-operative access foundation (J-AU0) and the Phase 5 Batch 1 receiver. It binds the duty manifest, the Sales-origin source verifier and the
 * receiver the Sales release coordinator calls; there is no route, no registered permission and no public entry point that creates or changes a Job (every duty is SPECIFIED).
 */
class JobsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JobDutyManifest::class, fn (): JobDutyManifest => JobDutyManifest::shipped());
        // The one supported source is a Sales service release. The Sales verifier refuses any Job without a Sales lineage row, so an unsupported origin still fails closed, and
        // every lifecycle command is in any case refused while its duty is not ACTIVE.
        $this->app->bind(JobSalesOrigin::class, SalesJobOrigins::class);
        $this->app->bind(JobExecutionContextVerifier::class, SalesJobExecutionContextVerifier::class);
        // The Sales release coordinator hands a verified release to this receiver inside its own transaction; there is no other way to create a Job.
        $this->app->bind(ReleasedServiceReceiver::class, ReceiveReleasedService::class);
        $this->app->afterResolving(NumberingRegistry::class, static function (NumberingRegistry $registry): void {
            $registry->register(new JobNumbering);
        });
        // Registers (and strictly excludes from automatic growth) only duties that are ACTIVE; none is, so this is a no-op today.
        $this->app->afterResolving(PermissionRegistry::class, function (PermissionRegistry $registry): void {
            $this->app->make(JobDutyManifest::class)->registerWith($registry);
        });
    }
}
