<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Category;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Accounting\Support\AccountMappingAccess;
use Illuminate\Foundation\Http\FormRequest;

class IndexCategoryRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        if ($this->has('filter.income_account_id')
            || $this->has('filter.expense_account_id')
            || $this->has('filter.inventory_account_id')
        ) {
            return AccountMappingAccess::canView($this->user());
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:parent_id,search,default_valuation_method,has_children,income_account_id,expense_account_id,inventory_account_id'],
            // 0 is a legitimate request: "top-level categories only" (parent_id is null).
            // A plain integer|min:1 filter couldn't express that, so it's its own case.
            'filter.parent_id' => ['sometimes', 'integer', 'min:0'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.default_valuation_method' => ['sometimes', 'string', 'max:50'],
            'filter.has_children' => ['sometimes', 'boolean'],
            'filter.income_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.expense_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.inventory_account_id' => ['sometimes', 'integer', 'min:1'],
        ], $this->listQueryRules(['code', 'name', 'created_at', 'updated_at', 'default_valuation_method'], ['parent', 'children']));
    }
}
