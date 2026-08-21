<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Category;

use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Nullsafe, not a plain property access: Scramble calls rules() outside a
        // real request to infer types, where route('category') is null — see
        // docs/architecture/api-conventions.md's "generated client" note on making
        // such access safe rather than leaving Scramble unable to infer the rule.
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('categories')],
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('categories', 'code', $category?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'income_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'expense_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'inventory_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'default_valuation_method' => ['sometimes', 'nullable', Rule::enum(ValuationMethod::class)],
        ];
    }
}
