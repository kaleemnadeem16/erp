<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountAuthorityChangeResource extends JsonResource
{
    /** @return array{id:int, mode:string|null} */
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->resource['id'], 'mode' => $this->resource['mode'] ?? null];
    }
}
