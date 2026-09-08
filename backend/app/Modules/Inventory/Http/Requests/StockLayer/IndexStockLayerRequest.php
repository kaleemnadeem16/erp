<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockLayer;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cursor-paginated, per docs/architecture/api-conventions.md#pagination —
 * mirrors IndexStockMoveRequest: one row per receipt, appended continually,
 * unlike a page-based master-data list.
 */
class IndexStockLayerRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = array_merge([
            'filter' => ['sometimes', 'array:item_id,location_id,batch_id,source_type,open'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.location_id' => ['sometimes', 'integer', 'min:1'],
            'filter.batch_id' => ['sometimes', 'integer', 'min:1'],
            'filter.source_type' => ['sometimes', 'string', 'max:255'],
            // An item's still-open FIFO layers — stock_layers_open_fifo exists
            // specifically to serve this filter efficiently.
            'filter.open' => ['sometimes', 'boolean'],
        ], $this->listQueryRules(['received_at'], ['item', 'location', 'batch']));

        unset($rules['page']);
        $rules['cursor'] = ['sometimes', 'string'];

        return $rules;
    }
}
