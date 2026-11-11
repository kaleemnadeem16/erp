<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One intake case as recorded: what the customer sent, never that they agreed. `consent_recorded` is always false in this release (no acceptance exists). Components appear only
 * where the caller may also see the quotation family; `hidden_component_count` counts the rest without describing them. `generation` is the value to send back as
 * `expected_generation` when adding an observation. An observation with `is_current` false has been corrected by a later one.
 */
final class CustomerDecisionCaseResource extends JsonResource
{
    /**
     * @return array{
     *   case: array{id:int,party_id:int,party_name:string|null,intake_identity:string,generation:int,recorded_by:int,recorded_by_name:string|null,recorded_at:string,consent_recorded:bool},
     *   components: list<array{id:int,intent_identity:string,family_id:int,original_scope_anchor_id:int,recorded_at:string}>,
     *   hidden_component_count: int,
     *   observations: list<array{id:int,predecessor_id:int|null,is_current:bool,source_kind:string,external_reference:string|null,source_version:string|null,customer_representative_snapshot:string|null,authority_verification_basis:string,occurrence_precision:string,occurred_at:string|null,occurred_until:string|null,source_timezone:string|null,factual_description:string,observation_state:string,recorded_by:int,recorded_by_name:string|null,recorded_at:string}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{case: array{id:int,party_id:int,party_name:string|null,intake_identity:string,generation:int,recorded_by:int,recorded_by_name:string|null,recorded_at:string,consent_recorded:bool}, components: list<array{id:int,intent_identity:string,family_id:int,original_scope_anchor_id:int,recorded_at:string}>, hidden_component_count: int, observations: list<array{id:int,predecessor_id:int|null,is_current:bool,source_kind:string,external_reference:string|null,source_version:string|null,customer_representative_snapshot:string|null,authority_verification_basis:string,occurrence_precision:string,occurred_at:string|null,occurred_until:string|null,source_timezone:string|null,factual_description:string,observation_state:string,recorded_by:int,recorded_by_name:string|null,recorded_at:string}>} $detail */
        $detail = $this->resource;

        return $detail;
    }
}
