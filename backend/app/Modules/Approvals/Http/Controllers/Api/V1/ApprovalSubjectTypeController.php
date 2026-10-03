<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Actions\SaveApprovalSubjectSetting;
use App\Modules\Approvals\Http\Requests\IndexApprovalSubjectTypeRequest;
use App\Modules\Approvals\Http\Requests\SaveApprovalSettingRequest;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Approvals\Support\ApprovalOperator;
use App\Modules\Approvals\Support\ApprovalSubject;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Http\JsonResponse;

/** The kinds of document that can be approved, what a policy may route on for each, and each one's approval mode. */
class ApprovalSubjectTypeController extends Controller
{
    public function index(IndexApprovalSubjectTypeRequest $request, ApprovalSubjectRegistry $subjects, ApprovalModes $modes): JsonResponse
    {
        return response()->json(['data' => collect($subjects->all())->map(fn ($subject): array => $this->describe($subject, $modes->for($subject->code())))->values()->all()]);
    }

    public function updateMode(SaveApprovalSettingRequest $request, string $subject_type, SaveApprovalSubjectSetting $save, ApprovalSubjectRegistry $subjects): JsonResponse
    {
        $setting = $save($subject_type, ApprovalMode::from($request->validated('mode')), $request->user());

        return response()->json(['data' => $this->describe($subjects->get($subject_type), $setting->mode)]);
    }

    /** @return array<string, mixed> */
    private function describe(ApprovalSubject $subject, ApprovalMode $mode): array
    {
        return [
            'code' => $subject->code(), 'label' => $subject->label(), 'approve_permission' => $subject->approvePermission(), 'mode' => $mode->value,
            'facts' => collect($subject->facts())->map(fn (array $fact, string $key): array => [
                'key' => $key, 'label' => $fact['label'], 'type' => $fact['type']->value,
                'operators' => collect(ApprovalOperator::cases())->filter(fn (ApprovalOperator $o): bool => $o->appliesTo($fact['type']))
                    ->map(fn (ApprovalOperator $o): array => ['value' => $o->value, 'label' => $o->label()])->values()->all(),
            ])->values()->all(),
        ];
    }
}
