<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests\ChartOfAccount;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Accounting\Support\AccountType;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexChartOfAccountRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:parent_id,type,status,search'],
            // 0 is a legitimate request: "top-level accounts only" (parent_id is null).
            'filter.parent_id' => ['sometimes', 'integer', 'min:0'],
            'filter.type' => ['sometimes', Rule::enum(AccountType::class)],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name', 'created_at'], ['parent', 'children']));
    }
}
