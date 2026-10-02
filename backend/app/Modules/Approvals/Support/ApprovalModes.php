<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Models\ApprovalSubjectSetting;

/** The company's approval mode for a subject type. No row means NONE: nothing is asked until a company opts in. */
final class ApprovalModes
{
    public function for(string $subjectType): ApprovalMode
    {
        return ApprovalSubjectSetting::query()->where('subject_type', $subjectType)->value('mode') === null
            ? ApprovalMode::None
            : ApprovalSubjectSetting::query()->where('subject_type', $subjectType)->firstOrFail()->mode;
    }
}
