<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\SupplierReturn;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\SupplierReturnReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowSupplierReturnRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return SupplierReturnReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
