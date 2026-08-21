<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests\ChartOfAccount;

use App\Modules\Accounting\Support\AccountType;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexChartOfAccountRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:parent_id,type,status,search'],
            // 0 is a legitimate request: "top-level accounts only" (parent_id is null).
            'filter.parent_id' => ['sometimes', 'integer', 'min:0'],
            'filter.type' => ['sometimes', Rule::enum(AccountType::class)],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
