<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Release;

use App\Modules\Jobs\Models\JobSalesOrigin;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Sales\Support\Handover\Release\JobSalesOrigin as JobSalesOriginPort;

/** The Jobs implementation of the Sales port that names the release event a Job came from. A Job with no Sales lineage row has no supported source integration. */
final class SalesJobOrigins implements JobSalesOriginPort
{
    public function releaseEventIdFor(ServiceJob $job): ?int
    {
        $id = JobSalesOrigin::query()->where('job_id', $job->id)->value('release_event_id');

        return $id === null ? null : (int) $id;
    }
}
