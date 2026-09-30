<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.authority.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', CompanyScoped::exists('users')],
            'subject_type' => ['required', 'string', Rule::in(array_keys(app(ApprovalSubjectRegistry::class)->all()))],
            'unlimited' => ['sometimes', 'boolean'],
            'ceiling_base_amount' => ['required_without:unlimited', 'nullable', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
