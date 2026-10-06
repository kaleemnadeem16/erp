<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexApprovalAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['approvals.authority.manage', 'approvals.authority.view']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:user_id,subject_type'],
            'filter.user_id' => ['sometimes', 'integer'],
            'filter.subject_type' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
