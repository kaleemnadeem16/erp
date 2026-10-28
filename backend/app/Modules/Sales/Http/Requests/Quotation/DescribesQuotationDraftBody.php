<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\LineMembership;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of a quotation draft write body, written once so the OpenAPI contract (and so the generated client) is typed. These rules only check types and
 * formats; they deliberately name no limit the Action owns (decimal places, source-kind authority, currency lock). `CreateQuotationDraft` and
 * `UpdateQuotationDraft` stay the one validator of values and still refuse an unknown header or line key — the rules never reject, filter or rewrite an unlisted
 * key, and the controller passes the raw JSON body on, not `validated()`.
 */
trait DescribesQuotationDraftBody
{
    /** @return array<string, mixed> */
    private function headerRules(): array
    {
        return [
            'recipient_party_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency_id' => ['sometimes', 'integer', 'min:1'],
            'pricing_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'tax_estimate_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_through_local_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    private function lineRules(): array
    {
        $fields = fn (string $list): array => [
            "{$list}.*.section_title" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$list}.*.description" => ['sometimes', 'string'],
            "{$list}.*.membership" => ['sometimes', Rule::enum(LineMembership::class)],
            "{$list}.*.choice_group_ref" => ['sometimes', 'nullable', 'string', 'max:80'],
            "{$list}.*.is_baseline" => ['sometimes', 'boolean'],
            "{$list}.*.item_id" => ['sometimes', 'integer', 'min:1'],
            "{$list}.*.manual_measure" => ['sometimes', 'string', 'max:100'],
            "{$list}.*.offered_uom_id" => ['sometimes', 'nullable', 'integer', 'min:1'],
            "{$list}.*.sort_order" => ['sometimes', 'integer', 'min:0'],
        ];
        // Exact decimals travel as text; the pattern checks the form only, so the Action keeps its precision and sign refusals.
        $decimal = 'regex:/^-?\d+(\.\d+)?$/';

        return [
            'add_lines' => ['sometimes', 'array'],
            'add_lines.*' => ['array'],
            'add_lines.*.scope_ref' => ['sometimes', 'string', 'max:80'],
            'add_lines.*.source_kind' => ['required', Rule::enum(PricingSourceKind::class)],
            'add_lines.*.offered_quantity' => ['required', 'string', $decimal],
            'add_lines.*.unit_price' => ['sometimes', 'nullable', 'string', $decimal],
            'update_lines' => ['sometimes', 'array'],
            'update_lines.*' => ['array'],
            'update_lines.*.id' => ['required', 'integer', 'min:1'],
            'update_lines.*.offered_quantity' => ['sometimes', 'string', $decimal],
            'update_lines.*.unit_price' => ['sometimes', 'nullable', 'string', $decimal],
            'remove_lines' => ['sometimes', 'array'],
            'remove_lines.*' => ['integer', 'min:1'],
        ] + $fields('add_lines') + $fields('update_lines');
    }

    /** A body that is not a JSON object is refused before any Action runs: malformed JSON must never read as an empty draft request. */
    private function requireJsonObject(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $decoded = json_decode((string) $this->getContent(), false);
            if (! $this->isJson() || ! $decoded instanceof \stdClass) {
                $validator->errors()->add('body', 'The request body must be a JSON object.');
            }
        });
    }
}
