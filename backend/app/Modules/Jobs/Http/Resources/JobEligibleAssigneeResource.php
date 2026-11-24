<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Queries\EligibleJobAssigneeProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EligibleJobAssigneeProjection */
final class JobEligibleAssigneeResource extends JsonResource
{
    /** @return array{id:int,name:string} */
    public function toArray(Request $request): array
    {
        /** @var EligibleJobAssigneeProjection $choice */
        $choice = $this->resource;

        return ['id' => $choice->id, 'name' => $choice->name];
    }
}
