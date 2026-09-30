<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ResourceRate;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Rules\Support\CompanyScoped;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.resource-rate.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => [
                'required', 'integer', CompanyScoped::exists('items'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $item = Item::query()->find($value);
                    if ($item !== null && ! in_array($item->type, [ItemType::Labour, ItemType::Plant], true)) {
                        $fail('Resource rates only apply to LABOUR or PLANT items.');
                    }
                },
            ],
            'rate' => ['required', 'decimal:0,6', 'gte:0'],
            'rate_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'effective_from' => [
                'required', 'date',
                Rule::unique('resource_rates')->where(fn ($query) => $query
                    ->where('company_id', app(CompanyContext::class)->id())
                    ->where('item_id', $this->input('item_id'))),
            ],
        ];
    }
}
