<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests\ChartOfAccount;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Rules\Support\CompanyScoped;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChartOfAccountRequest extends FormRequest
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
        // where route('chart_of_account') is null — see UpdateCategoryRequest for
        // the same note.
        /** @var ChartOfAccount|null $chartOfAccount */
        $chartOfAccount = $this->route('chart_of_account');

        return [
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('chart_of_accounts', 'code', $chartOfAccount?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'parent_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('chart_of_accounts')],
            'is_postable' => ['sometimes', 'boolean'],
            'currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
