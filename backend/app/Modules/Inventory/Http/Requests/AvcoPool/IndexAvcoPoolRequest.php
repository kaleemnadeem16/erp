<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\AvcoPool;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAvcoPoolRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
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
