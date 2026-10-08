<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\SupplierReturn;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\SupplierReturnReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class IndexSupplierReturnRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return SupplierReturnReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(['filter' => ['sometimes', 'array:warehouse_id,status,search'], 'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'], 'filter.status' => ['sometimes', 'in:DRAFT,POSTED,CANCELLED'], 'filter.search' => ['sometimes', 'string', 'max:255']], $this->listQueryRules(['created_at', 'posted_at', 'number'], ['warehouse', 'lines']));
    }
}
