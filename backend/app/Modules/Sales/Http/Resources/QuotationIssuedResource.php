<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The official record of an issued revision: number, instant, maker/checker and frozen header terms; totals only for prepare/issue holders of that revision (else null). No cost, margin, control outcome, fingerprint or tax evidence. */
final class QuotationIssuedResource extends JsonResource
{
    /** @return array{revision_id: int, family_id: int, official_number: string, issued_at: string, prepared_by: int, issued_by: int, currency_id: int, pricing_date: string, valid_through_local_date: string, expires_at: string, availability: string, scope_acceptance: list<array{issued_line_id: int, logical_scope_id: int, status: string}>|null, totals: array{net_total: string, optional_net_total: string|null, tax_total: string|null, gross_total: string|null}|null} */
    public function toArray(Request $request): array
    {
        /** @var array{revision_id: int, family_id: int, official_number: string, issued_at: string, prepared_by: int, issued_by: int, currency_id: int, pricing_date: string, valid_through_local_date: string, expires_at: string, availability: string, scope_acceptance: list<array{issued_line_id: int, logical_scope_id: int, status: string}>|null, totals: array{net_total: string, optional_net_total: string|null, tax_total: string|null, gross_total: string|null}|null} $data */
        $data = $this->resource;

        return $data;
    }
}
