<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support;

/**
 * The Jobs-local shape a released service will arrive in once a receiver exists: the Job's code, the receiver identity (purpose and business identity), the
 * source contract version and canonical intent hash, and the declared baseline basis. It carries no quantity or measure (none is known), no acceptance,
 * allocation or stage reference (those arrive as real FKs with the Sales integration) and no authority flag. Nothing accepts it yet: see
 * {@see ReleasedServiceValidator}, the only code that touches it.
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
