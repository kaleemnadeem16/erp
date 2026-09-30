<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexApprovalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:subject_type,status,subject_id'],
            'filter.subject_type' => ['sometimes', 'string', 'max:64'],
            'filter.subject_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', 'string', 'in:PENDING,APPROVED,REJECTED,WITHDRAWN,INVALIDATED,EXPIRED'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
