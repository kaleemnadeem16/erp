<?php

declare(strict_types=1);

namespace App\Http\Requests\ExchangeRate;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Support\RateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExchangeRateRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:from_currency_id,to_currency_id,rate_type,rate_date'],
            'filter.from_currency_id' => ['sometimes', 'integer', 'min:1'],
            'filter.to_currency_id' => ['sometimes', 'integer', 'min:1'],
            'filter.rate_type' => ['sometimes', Rule::enum(RateType::class)],
            'filter.rate_date' => ['sometimes', 'date_format:Y-m-d'],
        ], $this->listQueryRules(
            ['rate_date', 'rate', 'created_at'],
            ['from_currency', 'to_currency'],
        ));
    }
}
