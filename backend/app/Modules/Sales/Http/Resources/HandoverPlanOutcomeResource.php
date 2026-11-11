<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe service preparation projection; no commercial/cost evidence or authority inference. */
final class HandoverPlanOutcomeResource extends JsonResource
{
    /** @return array{plan_id:int,case_id:int,replayed:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{plan_id:int,case_id:int,replayed:bool} $data */
        $data = $this->resource;

        return $data;
    }
}
