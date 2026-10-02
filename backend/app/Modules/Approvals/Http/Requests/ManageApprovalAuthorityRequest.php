<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManageApprovalAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.authority.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
