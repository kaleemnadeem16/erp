<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The safe metadata `ReadQuotationDraft` returns, passed through unchanged: it carries no price, quantity, cost or commercial figure. */
class QuotationDraftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $draft */
        $draft = $this->resource;

        return $draft;
    }
}
