<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The same validation is used by direct Actions and HTTP; no arbitrary executable matching facts. */
final class ReferenceInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function parse(array $input): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'source_kind' => ['required', Rule::in(['CUSTOMER_AGREEMENT', 'PRICE_LIST', 'PROJECT_BASELINE'])],
            'source_key' => ['required', 'string', 'max:160'], 'source_version' => ['required', 'string', 'max:80'],
            'family_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'item_id' => ['nullable', 'integer', 'min:1'],
            'manual_measure' => ['nullable', 'string', 'max:80'], 'uom_id' => ['nullable', 'integer', 'min:1'], 'currency_id' => ['required', 'integer', 'min:1', 'exists:currencies,id'],
            'unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'], 'priority' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000'],
        ];
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'The reference contains unsupported fields.');
        }
        $data = Validator::make($input, $rules)->validate();
        $manual = ($data['item_id'] ?? null) === null;
        if (($manual && (($data['manual_measure'] ?? null) === null || ($data['family_id'] ?? null) === null || $data['source_kind'] !== 'PROJECT_BASELINE'))
            || (! $manual && ($data['manual_measure'] ?? null) !== null)
            || ($data['source_kind'] === 'CUSTOMER_AGREEMENT' && ($data['customer_id'] ?? null) === null)) {
            throw AuthorityRefusal::make('REFERENCE_INVALID', 'Name the Item and its measure, or an independently governed project-specific manual baseline. Customer agreements must name their customer.');
        }
        $data['unit_price'] = (string) StorageAmount::of($data['unit_price'], 'unit_price');
        if (StorageAmount::of($data['unit_price'])->isZero()) {
            throw AuthorityRefusal::make('REFERENCE_INVALID', 'A governed reference price must be greater than zero.', 'unit_price');
        }

        return $data;
    }
}
