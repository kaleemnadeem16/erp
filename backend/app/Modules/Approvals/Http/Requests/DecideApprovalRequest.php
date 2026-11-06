<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalRequestAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $approval = $this->route('approval_request');
        if ($user !== null && $approval instanceof ApprovalRequest && ! app(ApprovalRequestAccess::class)->canView($user, $approval)) {
            app(ApprovalRequestAccess::class)->hide($approval);
        }

        return $user !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ApprovalDecisionKind::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'step_id' => ['sometimes', 'integer'],
        ];
    }
}
