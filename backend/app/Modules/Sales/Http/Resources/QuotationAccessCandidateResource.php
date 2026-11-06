<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuotationAccessCandidateResource extends JsonResource
{
    /** @return array{id:int,name:string} */
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->resource->id, 'name' => $this->resource->name];
    }
}
