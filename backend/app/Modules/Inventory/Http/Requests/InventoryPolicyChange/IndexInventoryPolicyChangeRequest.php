<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryPolicyChange;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexInventoryPolicyChangeRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.policy-history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:policy'],
            'filter.policy' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['changed_at'], ['changedBy']));
    }
}
