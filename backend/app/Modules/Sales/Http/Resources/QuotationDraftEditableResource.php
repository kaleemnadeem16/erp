<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The editable shape of a draft: the same field names `PATCH quotations/{revision}` accepts, so a form can load it, change it and send back only what changed with
 * `expected_generation = input_generation`. Entered values only (discounts and overrides included, with the approval-needed code) — never a computed amount, FX evidence, cost, tax or control limit.
 *
 * @mixin QuotationRevision
 */
class QuotationDraftEditableResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var QuotationRevision $revision */
        $revision = $this->resource;

        return [
            'revision_id' => (int) $revision->id,
            'family_id' => (int) $revision->family_id,
            'state' => (string) $revision->state,
            'input_generation' => (int) $revision->input_generation,
            'recipient_party_id' => $revision->recipient_party_id === null ? null : (int) $revision->recipient_party_id,
            'currency_id' => (int) $revision->currency_id,
            'pricing_date' => $revision->pricing_date?->format('Y-m-d'),
            'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'),
            'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'),
            'current_opportunity_id' => $revision->family->current_opportunity_id === null ? null : (int) $revision->family->current_opportunity_id,
            'document_discount' => $revision->getAttribute('document_discount'),
            'lines' => $revision->lines->map(fn (QuotationDraftLine $line): array => [
                'id' => (int) $line->id,
                'scope_ref' => (string) $line->logicalScope->intent_ref,
                'section_title' => $line->section_title,
                'description' => (string) $line->description,
                'membership' => (string) $line->membership,
                'choice_group_ref' => $line->choiceGroup?->intent_ref,
                'is_baseline' => (bool) $line->is_baseline,
                'source_kind' => (string) $line->source_kind,
                'item_id' => $line->item_id === null ? null : (int) $line->item_id,
                'assembly_version_id' => $line->assembly_version_id === null ? null : (int) $line->assembly_version_id,
                'manual_measure' => $line->manual_measure,
                'offered_quantity' => (string) $line->offered_quantity,
                'offered_uom_id' => $line->offered_uom_id === null ? null : (int) $line->offered_uom_id,
                'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                'sort_order' => (int) $line->sort_order,
                'discount' => $line->discount_kind === null ? null : ['kind' => (string) $line->discount_kind, 'value' => (string) $line->discount_value],
                'net_override' => $line->net_override === null ? null : ['amount' => (string) $line->net_override, 'reason' => (string) $line->override_reason],
                'tax_declaration' => $line->getAttribute('tax_declaration'),
                'approval_required' => (bool) $line->approval_required,
                'approval_reason' => $line->approval_reason,
            ])->values()->all(),
        ];
    }
}
