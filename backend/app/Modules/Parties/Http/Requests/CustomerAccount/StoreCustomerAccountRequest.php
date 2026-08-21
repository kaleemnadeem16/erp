<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\CustomerAccount;

use App\Modules\Parties\Models\CustomerAccount;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerAccountRequest extends FormRequest
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
            // A party can hold at most one customer_account (unique(company_id, party_id))
            // — checked here for a field-specific 422 rather than only surfacing as
            // the generic conflict response once it reaches the DB constraint.
            'party_id' => ['required', 'integer', CompanyScoped::exists('parties'), CompanyScoped::unique('customer_accounts', 'party_id')],
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('customer_accounts', 'code')],
            // decimal(19,4): 15 integer digits, 4 decimal — bounded to the column's
            // real precision, or an oversized value hits Postgres as a raw numeric
            // overflow instead of a clean 422. No leading '-': a negative credit
            // limit isn't a meaningful state (null already means "no limit").
            'credit_limit' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            // smallint on Postgres is a real signed 16-bit column — Laravel's
            // "unsigned" is a MySQL-only modifier and adds no Postgres CHECK
            // constraint, so an out-of-range value would otherwise reach Postgres
            // directly as a raw "smallint out of range" error.
            'payment_terms_days' => ['required', 'integer', 'between:0,32767'],
            // The column is reserved for Phase 6, but accepting arbitrary ids now
            // would create dangling references that cannot be constrained later.
            'tax_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::prohibitedIf(fn (): bool => $this->input('tax_code_id') !== null),
            ],
            'receivable_account_id' => ['required', 'integer', CompanyScoped::exists('chart_of_accounts')],
            // Currency is global, not company-scoped (docs/architecture/multi-tenancy.md
            // rule 1) — a plain exists() is correct here, unlike the company-scoped FKs above.
            'default_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'custom_fields' => ['sometimes', new ValidCustomFields(CustomerAccount::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
