<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\ItemQuantityUnit;

use App\Rules\Support\CompanyScoped;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemQuantityUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.item.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'uom_id' => [
                'required', 'integer', CompanyScoped::exists('uoms'),
                Rule::unique('item_quantity_units')->where(fn ($query) => $query
                    ->where('company_id', app(CompanyContext::class)->id())
                    ->where('item_id', $this->input('item_id'))),
            ],
            'factor' => ['required', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'quantity_precision' => ['sometimes', 'nullable', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
        ];
    }
}
