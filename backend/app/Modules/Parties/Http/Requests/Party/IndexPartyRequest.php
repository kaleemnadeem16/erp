<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPartyRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:status,search'],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name', 'created_at'], ['customer_account', 'supplier_account']));
    }
}
