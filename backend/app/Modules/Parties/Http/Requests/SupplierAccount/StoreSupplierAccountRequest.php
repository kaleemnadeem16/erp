<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\SupplierAccount;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Support\AccountTermsAccess;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.supplier-account.manage') === true
            && AccountMappingAccess::canWrite($this->user(), $this->all())
            && AccountTermsAccess::canWrite($this->user(), $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A party can hold at most one supplier_account (unique(company_id, party_id))
            // — checked here for a field-specific 422 rather than only surfacing as
            // the generic conflict response once it reaches the DB constraint.
            'party_id' => ['required', 'integer', CompanyScoped::exists('parties'), CompanyScoped::unique('supplier_accounts', 'party_id')],
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('supplier_accounts', 'code')],
            'payment_terms_days' => ['required', 'integer', 'between:0,32767'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'between:0,32767'],
            'payable_account_id' => ['required', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'default_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'custom_fields' => ['sometimes', new ValidCustomFields(SupplierAccount::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
