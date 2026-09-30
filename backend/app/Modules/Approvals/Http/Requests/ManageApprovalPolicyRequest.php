<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManageApprovalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
