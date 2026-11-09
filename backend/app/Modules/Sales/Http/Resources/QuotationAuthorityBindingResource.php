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
        return ['family_id' => $this->resource['family_id'], 'revision_id' => $this->resource['revision_id'], 'bound_lines' => count($this->resource['references'])];
    }
}
