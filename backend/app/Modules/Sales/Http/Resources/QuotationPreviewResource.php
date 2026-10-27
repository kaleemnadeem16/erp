<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The preview `PreviewQuotation` computes, passed through unchanged: PREVIEW_ONLY authority, no cost, tax UNKNOWN, never issue-ready. */
class QuotationPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $preview */
        $preview = $this->resource;

        return $preview;
    }
}
