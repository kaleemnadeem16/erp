<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Support\ApprovalOperator;
use App\Modules\Approvals\Support\ExpiryAction;
use App\Modules\Approvals\Support\Review\ReviewProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApprovalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['prohibited'],
            'code' => ['prohibited',  'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
            'reason' => ['sometimes', 'string', 'max:1000'],
            'conditions' => ['sometimes', 'array', 'max:20'],
            'conditions.*.fact' => ['required', 'string', 'max:64'],
            'conditions.*.operator' => ['required', Rule::enum(ApprovalOperator::class)],
            'conditions.*.value' => ['required'],
            'steps' => ['sometimes', 'array', 'min:1', 'max:20'],
            'steps.*.review_profile' => ['sometimes', Rule::enum(ReviewProfile::class)],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.stage' => ['sometimes', 'integer', 'between:1,50'],
            'steps.*.required_approvals' => ['required', 'integer', 'between:1,20'],
            'steps.*.permission' => ['sometimes', 'nullable', 'string', 'max:128'],
            'steps.*.use_authority_limit' => ['sometimes', 'boolean'],
            'steps.*.allow_submitter' => ['sometimes', 'boolean'],
            'steps.*.expires_after_hours' => ['sometimes', 'nullable', 'integer', 'between:1,8760'],
            'steps.*.on_expiry' => ['sometimes', Rule::enum(ExpiryAction::class)],
            'steps.*.escalation_permission' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
