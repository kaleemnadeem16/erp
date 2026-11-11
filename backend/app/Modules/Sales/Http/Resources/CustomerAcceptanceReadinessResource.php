<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe acceptance projection; no cost, margin or internal fingerprint. */
final class CustomerAcceptanceReadinessResource extends JsonResource
{
    /** @return array{acceptance_id:int,assessment_version:string,path:string,status:string,preparation_ready:bool,missing:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,assessment_version:string,path:string,status:string,preparation_ready:bool,missing:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>} $data */
        $data = $this->resource;

        return $data;
    }
}
