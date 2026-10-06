<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\CustomerAccount;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Support\AccountTermsAccess;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.customer-account.manage') === true
            && AccountMappingAccess::canWrite($this->user(), $this->all())
            && AccountTermsAccess::canWrite($this->user(), $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Nullsafe: Scramble calls rules() outside a real request to infer types,
        // where route('customer_account') is null — see UpdateCategoryRequest for
        // the same note.
        /** @var CustomerAccount|null $customerAccount */
        $customerAccount = $this->route('customer_account');

        return [
            'party_id' => ['sometimes', 'integer', CompanyScoped::exists('parties'), CompanyScoped::unique('customer_accounts', 'party_id', $customerAccount?->id)],
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('customer_accounts', 'code', $customerAccount?->id)],
            'credit_limit' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,32767'],
            'tax_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::prohibitedIf(fn (): bool => $this->input('tax_code_id') !== null),
            ],
            'receivable_account_id' => ['sometimes', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'default_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'custom_fields' => ['sometimes', new ValidCustomFields(CustomerAccount::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
