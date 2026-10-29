<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The editable shape of a draft: the same field names `PATCH quotations/{revision}` accepts, so a form can load it, change it and send back only what changed with
 * `expected_generation = input_generation`. Entered values only — never a computed amount, FX evidence, cost, tax or control.
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
                'manual_measure' => $line->manual_measure,
                'offered_quantity' => (string) $line->offered_quantity,
                'offered_uom_id' => $line->offered_uom_id === null ? null : (int) $line->offered_uom_id,
                'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                'sort_order' => (int) $line->sort_order,
            ])->values()->all(),
        ];
    }
}
