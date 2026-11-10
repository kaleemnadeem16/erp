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
        /** @var array{id:int,mode?:string|null} $data */
        $data = $this->resource;

        return ['id' => (int) $data['id'], 'mode' => $data['mode'] ?? null];
    }
}
