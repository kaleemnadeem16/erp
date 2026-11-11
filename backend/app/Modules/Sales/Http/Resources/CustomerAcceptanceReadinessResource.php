<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe acceptance projection; no cost, margin or internal fingerprint. */
final class CustomerAcceptanceReadinessResource extends JsonResource
{
    /** @return array{acceptance_id:int,assessment_version:string,path:string,status:string,missing:list<string>} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,assessment_version:string,path:string,status:string,missing:list<string>} $data */
        $data = $this->resource;

        return $data;
    }
}
