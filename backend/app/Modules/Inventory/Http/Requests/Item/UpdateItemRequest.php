<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Item;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Nullsafe: Scramble calls rules() outside a real request to infer types,
        // where route('item') is null — see UpdateCategoryRequest for the same note.
        /** @var Item|null $item */
        $item = $this->route('item');

        return [
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('items', 'code', $item?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', Rule::enum(ItemType::class)],
            'stock_uom_id' => ['sometimes', 'integer', CompanyScoped::exists('uoms')],
            'is_stocked' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'is_sellable' => ['sometimes', 'boolean'],
            'valuation_method' => ['sometimes', 'nullable', Rule::enum(ValuationMethod::class)],
            'tracking' => ['sometimes', Rule::enum(ItemTracking::class)],
            'category_id' => ['sometimes', 'integer', CompanyScoped::exists('categories')],
            'default_supplier_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('supplier_accounts')],
            'income_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'expense_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'inventory_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'custom_fields' => ['sometimes', new ValidCustomFields(Item::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
