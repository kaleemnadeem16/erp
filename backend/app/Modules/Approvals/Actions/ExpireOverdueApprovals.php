<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalStepStatus;
use App\Modules\Approvals\Support\ExpiryAction;

/**
 * Applies step deadlines (ADR 0070 §9), never deciding anything: a FLAG_OVERDUE step is marked overdue (and its
 * escalation capability, if any, starts to count); an EXPIRE_REQUEST step ends the request as EXPIRED.
 *
 * @return array{flagged: int, expired: int}
 */
final class ExpireOverdueApprovals
{
    public function __construct(private readonly InvalidateApprovals $invalidate) {}

    /** @return array{flagged: int, expired: int} */
    public function __invoke(): array
    {
        $flagged = 0;
        $expired = 0;
        $steps = ApprovalRequestStep::query()->where('status', ApprovalStepStatus::Open)->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())->with(['policyStep', 'request'])->get();
        foreach ($steps as $step) {
            $request = $step->request;
            if (! $request->isOpen()) {
                continue;
            }
            if ($step->policyStep->on_expiry === ExpiryAction::ExpireRequest) {
                ($this->invalidate)($request, 'The approval expired before it was decided.', ApprovalRequestStatus::Expired);
                $expired++;
            } elseif ($step->overdue_at === null) {
                $step->update(['overdue_at' => now()]);
                $flagged++;
            }
        }

        return ['flagged' => $flagged, 'expired' => $expired];
    }
}
