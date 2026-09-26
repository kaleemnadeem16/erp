<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ResourceRate;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexResourceRateRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:item_id'],
            'filter.item_id' => ['sometimes', 'integer'],
        ], $this->listQueryRules(['effective_from', 'created_at'], ['item', 'rateUom']));
    }
}
