<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Jobs\Models\ServiceJob;

/** Implemented by the Jobs module: the release event a Job came from, or null when the Job has no Sales origin (an unsupported origin, which the Sales verifier refuses). */
interface JobSalesOrigin
{
    public function releaseEventIdFor(ServiceJob $job): ?int;
}
