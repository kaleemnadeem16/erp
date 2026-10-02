<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexApprovalSubjectTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['approvals.policy.manage', 'approvals.settings.manage', 'approvals.authority.manage', 'approvals.history.view']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
