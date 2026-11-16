<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Jobs\Models\JobBaseline;
use App\Modules\Jobs\Models\JobBaselineComponent;
use App\Modules\Jobs\Models\JobCompletion;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobSalesOrigin;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Sales\Models\HandoverFulfilmentReceipt;
use App\Modules\Sales\Models\QuotationIssuedLine;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes and verifies the Sales fulfilment receipt of a completed Sales-origin Job. `record` runs inside the COMPLETE command's transaction on the runtime connection, after
 * the source fences and the Job head lock; it never takes a source lock itself (the lock order stays source fences, then the Job head). The database guard proves the same
 * facts again, so a raw write cannot forge what this class checks.
 */
final class SalesServiceFulfilment implements ServiceFulfilmentRecorder
{
    public function record(FulfilmentFact $fact): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A fulfilment receipt must be written inside the completion transaction.');
        }
        $origin = JobSalesOrigin::query()->where('job_id', $fact->jobId)->first()
            ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'This Job has no supported source integration.', 'job_id');
        $event = JobEvent::query()->where(['id' => $fact->completionEventId, 'job_id' => $fact->jobId])->first();
        if ($event === null || $event->operation !== 'COMPLETE' || (int) $event->actor_id !== $fact->actorId) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::SOURCE_CONTEXT_UNAVAILABLE, 'The completion evidence does not name this Job completion.', 'job_id');
        }
        $existing = HandoverFulfilmentReceipt::query()->where('event_id', $fact->completionEventId)->first();
        if ($existing !== null) {
            return $existing->id;
        }
        $baseline = JobBaseline::query()->where('job_id', $fact->jobId)->orderByDesc('version')->firstOrFail();
        $component = JobBaselineComponent::query()->where(['job_id' => $fact->jobId, 'baseline_id' => $baseline->id])->firstOrFail();
        $issued = QuotationIssuedLine::query()->findOrFail($component->issued_line_id);

        return HandoverFulfilmentReceipt::query()->create([
            'job_id' => $fact->jobId, 'event_id' => $fact->completionEventId, 'release_event_id' => $origin->release_event_id, 'issued_line_id' => $component->issued_line_id,
            'basis_id' => $component->basis_id, 'condition_identity' => $component->condition_identity, 'deliverable_digest' => self::digest((string) $issued->description),
            'applicability_pin' => $fact->sourcePin, 'completed_by' => $fact->actorId,
        ])->id;
    }

    public function verify(int $jobId): string
    {
        $receipt = HandoverFulfilmentReceipt::query()->where('job_id', $jobId)->first();
        if ($receipt === null) {
            return FulfilmentState::MISSING;
        }
        $completion = JobCompletion::query()->where('job_id', $jobId)->first();
        $origin = JobSalesOrigin::query()->where('job_id', $jobId)->first();
        $baseline = JobBaseline::query()->where('job_id', $jobId)->orderByDesc('version')->first();
        $component = $baseline === null ? null : JobBaselineComponent::query()->where(['job_id' => $jobId, 'baseline_id' => $baseline->id])->first();
        $issued = $component === null ? null : QuotationIssuedLine::query()->find($component->issued_line_id);
        if ($completion === null || $origin === null || $component === null || $issued === null) {
            return FulfilmentState::MISMATCH;
        }
        $agrees = $receipt->event_id === $completion->event_id
            && $receipt->release_event_id === $origin->release_event_id && $receipt->release_event_id === $completion->release_event_id
            && $receipt->issued_line_id === $component->issued_line_id && $receipt->basis_id === $component->basis_id
            && $receipt->condition_identity === $component->condition_identity && $receipt->condition_identity === $completion->condition_identity
            && $receipt->completed_by === $completion->completed_by
            && hash_equals($receipt->deliverable_digest, self::digest((string) $issued->description));

        return $agrees ? FulfilmentState::OK : FulfilmentState::MISMATCH;
    }

    /** sha256 of the agreed deliverable text exactly as the scope declaration and the database compute it (one definition: {@see ScopeHeader::digest}). */
    public static function digest(string $description): string
    {
        return ScopeHeader::digest($description);
    }
}
