<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One version of a seller's tax declaration for a manual line. Append-only evidence: a later version supersedes, it never replaces. */
class QuotationTaxDeclarationResource extends JsonResource
{
    /** @return array{line_id: int|null, scope_ref: string, version_no: int, nature: string, classification: string, reason: string, declared_by: int, declared_at: string} */
    public function toArray(Request $request): array
    {
        /** @var array{line_id: int|null, scope_ref: string, version_no: int, nature: string, classification: string, reason: string, declared_by: int, declared_at: string} $row */
        $row = $this->resource;

        return $row;
    }
}
