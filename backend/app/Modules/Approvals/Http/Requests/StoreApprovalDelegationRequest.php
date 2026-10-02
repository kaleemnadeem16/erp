<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegator_id' => ['sometimes', 'integer', CompanyScoped::exists('users')],
            'delegate_id' => ['required', 'integer', CompanyScoped::exists('users')],
            'subject_type' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(app(ApprovalSubjectRegistry::class)->all()))],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
