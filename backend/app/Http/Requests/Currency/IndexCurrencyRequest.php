<?php

declare(strict_types=1);

namespace App\Http\Requests\Currency;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexCurrencyRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:search'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name'], []));
    }
}
