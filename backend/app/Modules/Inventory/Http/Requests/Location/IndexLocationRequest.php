<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Location;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexLocationRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(['filter' => ['sometimes', 'array:warehouse_id,active,search'], 'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'], 'filter.active' => ['sometimes', 'boolean'], 'filter.search' => ['sometimes', 'string', 'max:255']], $this->listQueryRules(['code', 'name', 'created_at'], ['warehouse']));
    }
}
