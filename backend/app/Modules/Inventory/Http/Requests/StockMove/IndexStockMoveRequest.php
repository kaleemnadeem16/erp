<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockMove;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\StockVisibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cursor-paginated, per docs/architecture/api-conventions.md#pagination — the
 * stock ledger is a frequently-appended list, unlike every page-based master
 * data index. `page` from ValidatesListQuery is meaningless here and dropped
 * in favour of `cursor`.
 */
class IndexStockMoveRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return StockVisibility::movement($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = array_merge([
            'filter' => ['sometimes', 'array:item_id,warehouse_id,location_id,batch_id,source_type,moved_from,moved_to,search'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.location_id' => ['sometimes', 'integer', 'min:1'],
            'filter.batch_id' => ['sometimes', 'integer', 'min:1'],
            'filter.source_type' => ['sometimes', 'string', 'max:255'],
            'filter.moved_from' => ['sometimes', 'date'],
            'filter.moved_to' => ['sometimes', 'date', 'after_or_equal:filter.moved_from'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['moved_at'], ['item', 'from_location', 'to_location', 'batch', 'valuation_entries']));

        unset($rules['page']);
        $rules['cursor'] = ['sometimes', 'string'];

        return $rules;
    }
}
