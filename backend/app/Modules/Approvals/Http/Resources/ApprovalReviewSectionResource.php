<?php

namespace App\Modules\Approvals\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalReviewSectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['title' => (string) $this->resource['title'], 'fields' => ApprovalReviewFieldResource::collection($this->resource['fields'])];
    }
}
