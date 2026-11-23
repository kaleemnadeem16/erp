<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Support\Lifecycle\JobCloseoutOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobCloseoutOutcome */
final class JobCloseoutAssessmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JobCloseoutOutcome $outcome */
        $outcome = $this->resource;

        return [
            'job_id' => $outcome->jobId,
            'assessment_id' => $outcome->assessmentId,
            'status' => $outcome->status,
            'blockers' => $outcome->blockers,
            'generation' => $outcome->generation,
            'replayed' => $outcome->replayed,
        ];
    }
}
