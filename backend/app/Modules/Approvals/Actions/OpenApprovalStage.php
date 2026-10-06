<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalStepStatus;

/** Opens every step of a stage together (steps sharing a stage run in parallel) and starts their expiry clocks. */
final class OpenApprovalStage
{
    public function __invoke(ApprovalRequest $request, int $stage): void
    {
        $request = ApprovalRequest::query()->findOrFail($request->id);
        foreach ($request->steps()->where('stage', $stage)->with('policyStep')->get() as $step) {
            $hours = $step->policyStep->expires_after_hours;
            $step->update([
                'status' => ApprovalStepStatus::Open, 'opened_at' => now(),
                'deadline_at' => $hours === null ? null : now()->addHours($hours),
            ]);
        }
    }
}
