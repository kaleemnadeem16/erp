<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A numbering payload already shaped as an array by the service that built it. */
class NumberingArrayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $this->resource;

        return $payload;
    }
}
