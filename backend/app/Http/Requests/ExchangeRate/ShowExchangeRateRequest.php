<?php

declare(strict_types=1);

namespace App\Http\Requests\ExchangeRate;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowExchangeRateRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['from_currency', 'to_currency']);
    }
}
