<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One intake case in the list: identity, the customer, counts and the generation. Only the already-authorised projection of `ListCustomerDecisionCases`. */
final class CustomerDecisionCaseSummaryResource extends JsonResource
{
    /** @return array{id:int,party_id:int,party_name:string|null,intake_identity:string,generation:int,component_count:int,observation_count:int,recorded_by:int,recorded_at:string|null} */
    public function toArray(Request $request): array
    {
        /** @var array{id:int,party_id:int,party_name:string|null,intake_identity:string,generation:int,component_count:int,observation_count:int,recorded_by:int,recorded_at:string|null} $row */
        $row = $this->resource;

        return $row;
    }
}
