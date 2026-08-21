<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\CustomerAccount;

use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:party_id,status,search'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
