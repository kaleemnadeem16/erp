<?php

declare(strict_types=1);

namespace App\Http\Requests\ExchangeRate;

use App\Models\ExchangeRate;
use App\Support\RateType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_currency_id' => ['required', 'integer', 'exists:currencies,id', 'different:to_currency_id'],
            'to_currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'rate' => ['required', 'string', 'regex:/^(?=\d)(?:\d{1,10})(?:\.\d{1,9})?$/', 'gt:0'],
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'rate_type' => ['required', Rule::enum(RateType::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // The DB's composite unique constraint would catch this too, but as a raw
        // QueryException — this turns it into the same 422 shape as any other
        // validation failure. ExchangeRate::query() applies BelongsToCompany's
        // scope, so this is already company-scoped.
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $duplicate = ExchangeRate::query()
                ->where('from_currency_id', $this->input('from_currency_id'))
                ->where('to_currency_id', $this->input('to_currency_id'))
                ->whereDate('rate_date', $this->input('rate_date'))
                ->where('rate_type', $this->input('rate_type'))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'rate_date',
                    'A rate already exists for this currency pair, date, and rate type.'
                );
            }
        });
    }
}
