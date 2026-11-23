<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Minimal projection: the release event and the one numbered Job it created; never the full Job record, costs or evidence. */
final class ServiceReleaseOutcomeResource extends JsonResource
{
    /** @return array{release_event_id:int,job_id:int,job_code:string,replayed:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{release_event_id:int,job_id:int,job_code:string,replayed:bool} $data */
        $data = $this->resource;

        return $data;
    }
}
