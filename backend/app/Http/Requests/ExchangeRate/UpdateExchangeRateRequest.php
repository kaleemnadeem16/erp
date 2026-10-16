<?php

declare(strict_types=1);

namespace App\Http\Requests\ExchangeRate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the rate value itself is editable. The currency pair, date and rate_type
 * identify *which* rate this is — changing any of them is a new rate, not a
 * correction of this one, so they're not accepted here.
 */
class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('currency.exchange-rate.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate' => ['required', 'string', 'regex:/^(?=\d)(?:\d{1,10})(?:\.\d{1,9})?$/', 'numeric', 'gt:0'],
        ];
    }
}
