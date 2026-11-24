<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The current agreed completion conditions of one accepted service (head version only). */
final class CompletionBasisResource extends JsonResource
{
    /** @return array{acceptance_id:int,current:array{basis_id:int,version:int,terms_source:string,agreed_text:string,materials_scope:string,subcontract_scope:string,evidence_current:bool,recorded_at:string}|null} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,current:array{basis_id:int,version:int,terms_source:string,agreed_text:string,materials_scope:string,subcontract_scope:string,evidence_current:bool,recorded_at:string}|null} $data */
        $data = $this->resource;

        return $data;
    }
}
