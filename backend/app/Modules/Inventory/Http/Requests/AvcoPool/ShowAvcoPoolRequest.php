<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\AvcoPool;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\StockVisibility;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class ShowAvcoPoolRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        // A pool's quantity and value span warehouses, so it is not shown to a person limited to their units (ADR 0089).
        return StockVisibility::stock($this->user()) && WarehouseScope::unrestricted($this->user(), StockVisibility::STOCK);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'warehouse', 'batch']);
    }
}
