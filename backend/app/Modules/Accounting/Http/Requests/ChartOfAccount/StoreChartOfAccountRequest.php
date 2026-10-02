<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests\ChartOfAccount;

use App\Modules\Accounting\Support\AccountType;
use App\Rules\Support\CompanyScoped;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounting.chart-of-account.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('chart_of_accounts', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'is_postable' => ['sometimes', 'boolean'],
            // Currency is global, not company-scoped — a plain exists() is correct
            // here, unlike parent_id above.
            'currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
