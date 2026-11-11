<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe acceptance projection; no cost, margin or internal fingerprint. */
final class CustomerAcceptanceOutcomeResource extends JsonResource
{
    /** @return array{acceptance_id:int,case_id:int,replayed:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,case_id:int,replayed:bool} $data */
        $data = $this->resource;

        return $data;
    }
}
