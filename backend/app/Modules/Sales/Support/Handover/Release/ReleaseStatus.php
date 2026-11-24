<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Sales\Models\HandoverReleaseEvent;

/**
 * The effective release status of one prepared stage, DERIVED from the immutable `handover_release_events` row (preparation rows are never updated). The Job reference comes from
 * the Jobs receiver port and is returned only to a person who may independently see Jobs (`job.view`); otherwise the release is reported without it. Reads only.
 */
final class ReleaseStatus
{
    public const JOB_VISIBLE = 'VISIBLE';

    public const JOB_NOT_PERMITTED = 'NOT_PERMITTED';

    public const JOB_UNAVAILABLE = 'UNAVAILABLE';

    /** @return array{release_event_id:int,released_at:string,job_access:string,job:array{id:int,code:string}|null}|null */
    public static function of(User $actor, int $stageId): ?array
    {
        $event = HandoverReleaseEvent::query()->where(['stage_id' => $stageId, 'purpose' => HandoverReleaseEvent::PURPOSE_SERVICE_JOB_RECEIPT])->first();
        if ($event === null) {
            return null;
        }
        $out = ['release_event_id' => (int) $event->id, 'released_at' => $event->released_at->toIso8601String(), 'job_access' => self::JOB_NOT_PERMITTED, 'job' => null];
        if (! JobAccess::may($actor, 'job.view')) {
            return $out;
        }
        $reference = app()->bound(ReleasedServiceReceiver::class) ? app(ReleasedServiceReceiver::class)->referenceFor($event->id) : null;
        if ($reference === null) {
            return ['job_access' => self::JOB_UNAVAILABLE] + $out;
        }

        return ['job_access' => self::JOB_VISIBLE, 'job' => ['id' => $reference->jobId, 'code' => $reference->jobCode]] + $out;
    }
}
