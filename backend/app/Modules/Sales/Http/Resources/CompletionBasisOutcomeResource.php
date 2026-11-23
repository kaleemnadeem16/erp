<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Minimal projection: no evidence, text, scope or source detail. */
final class CompletionBasisOutcomeResource extends JsonResource
{
    /** @return array{basis_id:int,version:int,replayed:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{basis_id:int,version:int,replayed:bool} $data */
        $data = $this->resource;

        return ['basis_id' => $data['basis_id'], 'version' => $data['version'], 'replayed' => $data['replayed']];
    }
}
