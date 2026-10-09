<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/** Hand an enquiry to an active member, or leave it unassigned. Responsibility only: no access is granted (ADR 0106). */
final class AssignLead
{
    public function __invoke(Lead $lead, ?int $assigneeId, User $actor): Lead
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_ASSIGN, 'assign an enquiry');
        SalesRecords::requireCompanyActor($actor);

        return DB::transaction(function () use ($lead, $assigneeId, $actor): Lead {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $assignee = $assigneeId === null ? null : SalesRecords::eligibleMember($assigneeId, 'assigned_to');
            if ($locked->assigned_to === $assignee?->id) {
                return $locked;
            }
            $from = $locked->assigned_to;
            $locked->update(['assigned_to' => $assignee?->id]);
            SalesRecords::record($actor, SalesEvent::LEAD_ASSIGNED, $locked, null, null, ['from' => $from, 'to' => $assignee?->id]);

            return $locked->refresh();
        });
    }
}
