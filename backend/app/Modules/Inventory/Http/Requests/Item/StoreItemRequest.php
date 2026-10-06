<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Item;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.item.manage') === true
            && AccountMappingAccess::canWrite($this->user(), $this->all());
    }

    /**
     * Item::code() lowercases on write (Postgres is case-sensitive — see
     * docs/domain/items.md "Codes and case sensitivity") — normalise here too, or
     * the uniqueness check below validates a value the model will never actually
     * store, and a same-code-different-case submission would pass validation only
     * to collide at the database and fall back to the generic conflict response.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->exists('custom_fields')) {
            $this->merge(['custom_fields' => []]);
        }
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('items', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['required', Rule::enum(ItemType::class)],
            'stock_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'quantity_precision' => ['sometimes', 'nullable', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'is_stocked' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'is_sellable' => ['sometimes', 'boolean'],
            // No default here — omitted falls through to Category::
            // resolveDefaultValuationMethod() in Item::booted(), per ADR 0032.
            'valuation_method' => ['sometimes', 'nullable', Rule::enum(ValuationMethod::class)],
            'avco_pool_scope' => ['sometimes', 'nullable', Rule::enum(AvcoPoolScope::class)],
            'tracking' => ['sometimes', Rule::enum(ItemTracking::class)],
            'category_id' => ['required', 'integer', CompanyScoped::exists('categories')],
            'income_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'expense_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'inventory_account_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'custom_fields' => ['sometimes', new ValidCustomFields(Item::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
