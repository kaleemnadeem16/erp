<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Formulation;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexFormulationRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:output_item_id,customer_id,status,search'],
            'filter.output_item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.customer_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', 'string'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'created_at'], ['outputItem', 'outputUom', 'customer', 'activeVersion']));
    }
}
