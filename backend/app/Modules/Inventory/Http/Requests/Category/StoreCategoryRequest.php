<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Category;

use App\Modules\Inventory\Support\ValuationMethod;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.category.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('categories')],
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('categories', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'income_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'expense_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'inventory_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'default_valuation_method' => ['sometimes', 'nullable', Rule::enum(ValuationMethod::class)],
        ];
    }
}
