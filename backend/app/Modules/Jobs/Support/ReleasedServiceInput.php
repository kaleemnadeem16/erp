<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support;

/**
 * The source-neutral shape a released service arrives in at the Jobs receiver: the Job's code, the receiver identity (purpose and business identity), the source contract
 * version and canonical intent hash, and the declared baseline basis. It carries no quantity or measure, no acceptance, allocation or stage id (the Sales lineage lives in
 * `job_sales_origins` / `job_baseline_components`, written by the Jobs receiver action from a verified release) and no authority flag.
 * {@see ReleasedServiceValidator} shape-checks it against the table limits before anything is written.
 */
final readonly class ReleasedServiceInput
{
    public function __construct(
        public string $jobCode,
        public string $sourceContractVersion,
        public string $targetPurpose,
        public string $businessIdentity,
        public string $intentHash,
        public string $baselineFingerprint,
        public string $scopeDescription,
        public string $completionRuleVersion,
    ) {}
}
