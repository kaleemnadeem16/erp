<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobLifecycleOutcome */
final class JobCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JobLifecycleOutcome $outcome */
        $outcome = $this->resource;

        return [
            'job_id' => $outcome->jobId,
            'event_id' => $outcome->eventId,
            'sequence' => $outcome->sequence,
            'operation' => $outcome->operation,
            'state' => $outcome->state,
            'generation' => $outcome->generation,
            'replayed' => $outcome->replayed,
        ];
    }
}
