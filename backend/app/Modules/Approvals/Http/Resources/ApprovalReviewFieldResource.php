<?php

namespace App\Modules\Approvals\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalReviewFieldResource extends JsonResource
{
    /** @return array{label: string, value: string|null} */
    public function toArray(Request $request): array
    {
        return ['label' => (string) $this->resource['label'], 'value' => isset($this->resource['value']) ? (string) $this->resource['value'] : null];
    }
}
