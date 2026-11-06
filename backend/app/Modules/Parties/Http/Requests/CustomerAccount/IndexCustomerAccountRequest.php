<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\CustomerAccount;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerAccountRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:party_id,status,search,default_currency_id,receivable_account_id'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.default_currency_id' => ['sometimes', 'integer', 'min:1'],
            'filter.receivable_account_id' => ['sometimes', 'integer', 'min:1'],
        ], $this->listQueryRules(['code', 'payment_terms_days', 'status', 'created_at', 'updated_at'], ['party']));
    }
}
