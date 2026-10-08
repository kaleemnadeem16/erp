<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\SupplierReturn;

use Illuminate\Foundation\Http\FormRequest;

class CancelSupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.supplier-return.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
