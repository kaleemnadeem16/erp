<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\SupplierAccount;

use App\Modules\Parties\Models\SupplierAccount;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierAccountRequest extends FormRequest
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
        // Nullsafe: Scramble calls rules() outside a real request to infer types,
        // where route('supplier_account') is null — see UpdateCategoryRequest for
        // the same note.
        /** @var SupplierAccount|null $supplierAccount */
        $supplierAccount = $this->route('supplier_account');

        return [
            'party_id' => ['sometimes', 'integer', CompanyScoped::exists('parties'), CompanyScoped::unique('supplier_accounts', 'party_id', $supplierAccount?->id)],
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('supplier_accounts', 'code', $supplierAccount?->id)],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,32767'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'between:0,32767'],
            'payable_account_id' => ['sometimes', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'default_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'custom_fields' => ['sometimes', new ValidCustomFields(SupplierAccount::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
