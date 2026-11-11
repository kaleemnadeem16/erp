<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe acceptance projection; no cost, margin or internal fingerprint. */
final class CustomerAcceptanceResource extends JsonResource
{
    /** @return array{id:int,case_id:int,decision_component_id:int,revision_id:int,consent_evidence_id:int,policy_version_id:int,recorded_by:int,recorded_at:string,scope:array{issued_line_id:int,logical_scope_id:int,choice_group_id:int|null,description:string,source_kind:string,quantity:string,uom_id:int|null,unit_price:string,currency_id:int,net:string,tax:string|null,gross:string|null},acceptance_policy:string,unchanged_terms:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{id:int,case_id:int,decision_component_id:int,revision_id:int,consent_evidence_id:int,policy_version_id:int,recorded_by:int,recorded_at:string,scope:array{issued_line_id:int,logical_scope_id:int,choice_group_id:int|null,description:string,source_kind:string,quantity:string,uom_id:int|null,unit_price:string,currency_id:int,net:string,tax:string|null,gross:string|null},acceptance_policy:string,unchanged_terms:bool} $data */
        $data = $this->resource;

        return $data;
    }
}
