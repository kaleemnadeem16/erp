<?php

declare(strict_types=1);

namespace App\Http\Requests\Uom;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Support\UomDimension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUomRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:dimension,search'],
            'filter.dimension' => ['sometimes', Rule::enum(UomDimension::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name', 'created_at'], []));
    }
}
