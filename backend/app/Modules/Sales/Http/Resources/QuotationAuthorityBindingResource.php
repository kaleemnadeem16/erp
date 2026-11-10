<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationAuthorityBindingResource extends JsonResource
{
    /** @return array{family_id:int,revision_id:int,bound_lines:int} */
    public function toArray(Request $request): array
    {
        /** @var array{family_id:int,revision_id:int,references:array<int,int>} $data */
        $data = $this->resource;

        return ['family_id' => (int) $data['family_id'], 'revision_id' => (int) $data['revision_id'], 'bound_lines' => count($data['references'])];
    }
}
