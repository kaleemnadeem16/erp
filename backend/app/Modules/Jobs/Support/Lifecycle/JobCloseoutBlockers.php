<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

use App\Modules\Jobs\Models\JobCompletion;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;

/**
 * The one place that decides what blocks a closeout, shared by the assessment and by the close itself (which re-runs it under the Job lock, so an assessment can never be
 * stale-trusted). Stable codes only; nothing here names private source detail. Mandatory evidence: the first pilot has none beyond the source proof — a source that cannot prove
 * readiness (including a mandatory control whose adapter is missing) is SOURCE_NOT_READY, never a "no impact" pass.
 */
final class JobCloseoutBlockers
{
    public const JOB_NOT_COMPLETED = 'JOB_NOT_COMPLETED';

    public const COMPLETION_MISSING = 'COMPLETION_MISSING';

    public const OPEN_HOLDS = 'OPEN_HOLDS';

    public const SOURCE_NOT_READY = 'SOURCE_NOT_READY';

    public const SOURCE_CONDITIONS_CHANGED = 'SOURCE_CONDITIONS_CHANGED';

    /** @return list<string> sorted, unique */
    public function for(JobSnapshot $snapshot, JobExecutionContext $context): array
    {
        $blockers = [];
        if ($snapshot->job->state !== 'COMPLETED') {
            $blockers[] = self::JOB_NOT_COMPLETED;
        }
        $completion = JobCompletion::query()->where('job_id', $snapshot->job->id)->first();
        if ($completion === null) {
            $blockers[] = self::COMPLETION_MISSING;
        } elseif (isset($context->sourceVector['condition']) && $context->sourceVector['condition'] !== $completion->condition_identity) {
            // The agreed conditions the source holds now are not the ones the Job was completed against.
            $blockers[] = self::SOURCE_CONDITIONS_CHANGED;
        }
        if ($snapshot->openHoldIds !== []) {
            $blockers[] = self::OPEN_HOLDS;
        }
        if (! $context->commandReady) {
            $blockers[] = self::SOURCE_NOT_READY;
        }
        sort($blockers);

        return array_values(array_unique($blockers));
    }

    /** The source pin the assessment relied on (the applicability pin), for the evidence row; null when the source gives none. */
    public function pin(JobExecutionContext $context): ?string
    {
        $pin = $context->sourceVector['applicability_pin'] ?? null;

        return is_string($pin) && $pin !== '' ? $pin : null;
    }
}
