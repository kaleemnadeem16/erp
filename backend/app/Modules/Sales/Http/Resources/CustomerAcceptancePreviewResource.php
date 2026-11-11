<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe acceptance projection; no cost, margin or internal fingerprint. */
final class CustomerAcceptancePreviewResource extends JsonResource
{
    /** @return array{intent: array{revision_id:int,component_id:int,evidence_id:int,issued_line_id:int,expected_case_generation:int,expected_scope_generation:int,expected_choice_generation:int|null,unchanged_terms:bool}, terms: array{issued_line_id:int,description:string,quantity:string,uom_id:int|null,unit_price:string,currency_id:int,net:string,tax:string|null,gross:string|null}, unchanged_terms_statement:string,warnings:list<string>,preview_token:string,expires_in_seconds:int} */
    public function toArray(Request $request): array
    {
        /** @var array{intent: array{revision_id:int,component_id:int,evidence_id:int,issued_line_id:int,expected_case_generation:int,expected_scope_generation:int,expected_choice_generation:int|null,unchanged_terms:bool}, terms: array{issued_line_id:int,description:string,quantity:string,uom_id:int|null,unit_price:string,currency_id:int,net:string,tax:string|null,gross:string|null}, unchanged_terms_statement:string,warnings:list<string>,preview_token:string,expires_in_seconds:int} $data */
        $data = $this->resource;

        return $data;
    }
}
