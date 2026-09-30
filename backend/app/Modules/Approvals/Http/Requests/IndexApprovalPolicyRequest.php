<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexApprovalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['approvals.policy.manage', 'approvals.history.view']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:subject_type,status,code'],
            'filter.subject_type' => ['sometimes', 'string', 'max:64'],
            'filter.status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE,SUPERSEDED'],
            'filter.code' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
