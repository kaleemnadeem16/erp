<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/**
 * The Sales source's side of a Job's completion and closeout (Phase 5). Jobs calls `record` INSIDE the COMPLETE command's transaction, after the source fences and the Job head
 * lock, so the Job's completion evidence and the Sales fulfilment receipt are saved together or not at all; and `verify` when it assesses and closes a Job, so a closeout can
 * never rest on a missing or mismatched fulfilment. Neither call frees accepted capacity, changes a claim or touches any financial record.
 */
interface ServiceFulfilmentRecorder
{
    /** Appends the one immutable receipt of this completion; the same completion never yields a second. Refuses an unsupported origin or evidence that disagrees. @return int the receipt id */
    public function record(FulfilmentFact $fact): int;

    /** @return string one of FulfilmentState::OK / MISSING / MISMATCH for the Job's completion as it is now */
    public function verify(int $jobId): string;
}
