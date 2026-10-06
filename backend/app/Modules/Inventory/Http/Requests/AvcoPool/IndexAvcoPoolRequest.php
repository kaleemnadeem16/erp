<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\AvcoPool;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\StockVisibility;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAvcoPoolRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        // A pool's quantity and value span warehouses, so it is not shown to a person limited to their units (ADR 0089).
        return StockVisibility::stock($this->user()) && WarehouseScope::unrestricted($this->user(), StockVisibility::STOCK);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:item_id,warehouse_id,batch_id,status'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.batch_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(AvcoPoolStatus::class)],
        ], $this->listQueryRules(['updated_at'], ['item', 'warehouse', 'batch']));
    }
}
