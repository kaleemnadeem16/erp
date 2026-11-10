<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountAuthorityConfigurationResource extends JsonResource
{
    /** @return array{generation:int, mode:string, page:int, per_page:int, total:int, records:list<array<string,string|int|bool|null>>} */
    public function toArray(Request $request): array
    {
        /** @var array{generation:int, mode:string, page:int, per_page:int, total:int, records:list<array<string,string|int|bool|null>>} $data */
        $data = $this->resource;

        return $data;
    }
}
