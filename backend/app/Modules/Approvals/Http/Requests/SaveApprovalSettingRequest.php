<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Support\ApprovalMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveApprovalSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(ApprovalMode::class)],
        ];
    }
}
