<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ApplicationRate;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Estimating\Support\RecipeReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class IndexApplicationRateRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return RecipeReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:item_id,work_uom_id,is_default'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.work_uom_id' => ['sometimes', 'integer', 'min:1'],
            'filter.is_default' => ['sometimes', 'boolean'],
        ], $this->listQueryRules(['created_at'], ['item', 'workUom', 'uom', 'dimensionValues']));
    }
}
