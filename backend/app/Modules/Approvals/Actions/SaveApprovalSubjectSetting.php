<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Support\ActionAuthorization;

/** Sets a subject type's approval mode (ADR 0070 §2). Open requests are unaffected; the mode governs new submissions and guards. */
final class SaveApprovalSubjectSetting
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function __invoke(string $subjectType, ApprovalMode $mode, User $actor): ApprovalSubjectSetting
    {
        ActionAuthorization::require($actor, 'approvals.settings.manage', 'change approval settings');
        $actorId = $actor->id;
        $this->subjects->get($subjectType);

        return ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => $subjectType], ['mode' => $mode, 'changed_by' => $actorId]);
    }
}
