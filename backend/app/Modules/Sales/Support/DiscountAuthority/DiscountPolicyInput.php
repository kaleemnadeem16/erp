<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class DiscountPolicyInput
{
    public const CEILINGS = ['line_percent', 'line_base_amount', 'document_percent', 'document_base_amount', 'input_percent', 'input_base_amount'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'], 'priority' => ['required', 'integer'],
            'operation' => ['required', Rule::in(['ANY', 'DISCOUNT', 'OVERRIDE'])], 'enforcement' => ['required', Rule::in(['LINE', 'DOCUMENT', 'BOTH'])],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'unit_id' => ['nullable', 'integer', 'min:1'], 'contains_item_id' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'expiry_calendar_days' => ['required', 'integer', 'between:1,365'], 'reason' => ['required', 'string', 'max:1000'],
            'allow_higher_approver' => ['sometimes', 'boolean'],
            'bands' => ['required', 'array', 'min:1', 'max:100'], 'bands.*' => ['array:rank,label,line_percent,line_base_amount,document_percent,document_base_amount,input_percent,input_base_amount,exception_only'],
            'bands.*.rank' => ['required', 'integer', 'min:0', 'distinct'], 'bands.*.label' => ['required', 'string', 'max:100'], 'bands.*.exception_only' => ['required', 'boolean'],
        ];
        foreach (self::CEILINGS as $field) {
            $rules['bands.*.'.$field] = ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function parse(array $input): array
    {
        $rules = self::rules();
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'The policy contains unsupported matching fields.');
        }
        $data = Validator::make($input, $rules)->validate();
        foreach ($data['bands'] as &$band) {
            $hasLimit = false;
            foreach (self::CEILINGS as $field) {
                if (($band[$field] ?? null) === null) {
                    continue;
                }
                $amount = StorageAmount::of($band[$field], $field);
                if (str_ends_with($field, '_percent') && $amount->isGreaterThan(100)) {
                    throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'A percentage ceiling must be between zero and 100.', $field);
                }
                $band[$field] = (string) $amount;
                $hasLimit = true;
            }
            if (! $band['exception_only'] && ! $hasLimit) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Routine authority must have an explicit ceiling.', 'bands');
            }
            if ($band['exception_only'] && $hasLimit) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'An explicit exception band cannot also imply routine numerical clearance.', 'bands');
            }
            $line = ($band['line_percent'] ?? $band['line_base_amount'] ?? null) !== null;
            $document = ($band['document_percent'] ?? $band['document_base_amount'] ?? null) !== null;
            if (! $band['exception_only'] && (($data['enforcement'] !== 'DOCUMENT' && ! $line) || ($data['enforcement'] !== 'LINE' && ! $document))) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Each routine band needs limits for every selected enforcement level.', 'bands');
            }
        }
        unset($band);

        return $data;
    }
}
